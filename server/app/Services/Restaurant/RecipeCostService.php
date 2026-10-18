<?php

declare(strict_types=1);

namespace App\Services\Restaurant;

use App\Models\PingwinCatalogItem;
use App\Models\PingwinProductBom;
use App\Models\PingwinProductBomLine;
use App\Models\PingwinProductBomLineCost;
use App\Models\PingwinProductBomStore;
use App\Models\PingwinUnit;
use App\Models\ProductBomYield;
use App\Models\ProductUnitConversion;
use App\Services\SupplierArticleMapService;
use App\Services\SupplierDocumentLinesService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * XPLENDOR — Fatia 1: o custo das fichas técnicas calculado pela XPLENDOR, a partir do espelho
 * (fichas e compras), sem tocar no PingWin.
 *
 *  · Custo de um componente, por unidade base: das linhas de compra (faturas e faturas-recibo de
 *    fornecedor fechadas) dos últimos 90 dias: o último preço e o médio ponderado, com os
 *    descontos da linha (o total da linha já vem líquido), quantidade = qnt_base do raw. As linhas
 *    com preço acima de 4× ou abaixo de ¼ da mediana ficam de fora (contam como aviso).
 *    Sem compras em 90 dias: o último preço conhecido ("antigo"). Sem compras nenhumas: "sem custo".
 *  · Custo da linha = qnt_base × custo médio × (1 + desperdício/100). PRESSUPOSTO: nenhuma ficha
 *    lida tinha desperdício > 0, por isso a fórmula do PingWin com desperdício não foi confirmada.
 *    A recuperação mostra-se, não entra no custo.
 *  · Semi-produtos (componente com ficha): custo da ficha em vigor, recursivo, até 5 níveis e com
 *    proteção contra ciclos. Custo por unidade base = custo do lote ÷ rendimento confirmado (Fatia
 *    1b, product_bom_yields). Sem rendimento confirmado: ÷ 1, com o aviso "Rendimento por confirmar"
 *    e o custo dos pratos marcado como de baixa confiança. (O PingWin não usa a ficha do
 *    semi-produto nos pratos: usa o custo de stock dele, que no Molho é 0.)
 *  · Quantidade das compras (Fatia 1b): o fator confirmado por artigo e unidade (product_unit_
 *    conversions, do fornecedor ou geral) antes da qnt_base do raw. Diz-se sempre de onde veio.
 *  · Componente sem compras e com custo PingWin 0 (aparas, ossos): custo 0, aviso informativo.
 *  · Versão em vigor na data D e na loja L: a de valid_from mais recente ≤ D, não anulada, com L
 *    ativa (sem loja: com alguma loja ativa). Empate na data: a criada depois (key maior).
 *  · Fatia 3 (regra de ouro, unificada com a Engenharia de menu): o custo NUNCA usa o fator da
 *    unidade do PingWin (unit_value / qnt_base do raw). Quantidade de uma linha de compra para o
 *    custo: na unidade base, a quantidade da linha; noutra unidade, quantidade × fator confirmado
 *    na XPLENDOR; sem fator confirmado, a linha não dá preço ("sem conversão"). A qnt_base do raw
 *    continua nas linhas (a Auditoria usa-a para as suas regras), mas não entra no custo.
 *    Semi-produtos até 3 níveis. Dois modos de preço: média pesada de 90 dias (por omissão) e
 *    último custo. Desconto no cabeçalho da fatura: aviso (não se reparte pelas linhas).
 */
final class RecipeCostService
{
    public const WINDOW_DAYS = 90;
    public const OUTLIER_FACTOR = 4.0;
    public const PINGWIN_DIFF = 0.20;
    public const MAX_DEPTH = 3;

    /** Preço dos componentes: média pesada de 90 dias ou último custo. */
    public const PRICE_MODES = ['media', 'ultimo'];

    /** Os documentos de compra que dão preço: fatura e fatura-recibo de fornecedor (fechados). */
    public const PURCHASE_DOCCONFIGS = ['1209', '584955579139752236'];

    /** Unidade com nome de embalagem (caixa, grade, barril…) mas valor 1: fator suspeito. */
    private const PACK_NAME = '/(caixa|\bcx\b|pack|grade|grd|garraf|\bgrf\b|barril|bid[oõ]n|bid[aã]o|\bbd\b|embal|\bemb\b|saco|lata|balde|pacote|fardo|tabuleiro|\bbr\s?\d)/iu';

    private CarbonImmutable $today;

    /** @var array<string, list<array>> versões por artigo (com lojas e linhas) */
    private array $versions = [];

    /** @var array<string, array> custo de compra por componente */
    private array $purchase = [];

    /** @var array<string, PingwinCatalogItem> */
    private array $items = [];

    /** @var array<string, PingwinUnit> */
    private array $units = [];

    /** @var array<string, list<string>> unidades usadas nas compras de cada componente (90 dias) */
    private array $purchaseUnits = [];

    /** @var array<string, array> */
    private array $memo = [];

    /** @var array<string, list<array>> linhas de compra por componente (com unidade, NIF e fator usado) */
    private array $purchaseRows = [];

    /** @var array<string, array<string, ProductUnitConversion>> conversões confirmadas: artigo → "unidade|NIF" */
    private array $conversions = [];

    /** @var array<string, true> os artigos com os fatores confirmados já carregados */
    private array $conversionsLoaded = [];

    /** @var array<string, ProductBomYield> rendimentos confirmados por versão (bom) */
    private array $yields = [];

    /** @var array<string, true> artigos usados como semi-produto (linha com isbom) */
    private array $semiIds = [];

    /** @var array<string, array{description:string, tokens:list<string>}>|null artigos comprados nos últimos 90 dias */
    private ?array $purchased = null;

    /** media (por omissão) ou ultimo */
    private string $priceMode = 'media';

    public function __construct(private int $companyId, ?CarbonInterface $today = null)
    {
        $this->today = CarbonImmutable::parse(($today ?? now('Europe/Lisbon'))->toDateString());
    }

    /** O preço dos componentes: média pesada de 90 dias ("media") ou último custo ("ultimo"). */
    public function withPriceMode(string $mode): self
    {
        $mode = in_array($mode, self::PRICE_MODES, true) ? $mode : 'media';
        if ($mode !== $this->priceMode) {
            $this->priceMode = $mode;
            $this->memo = [];
        }

        return $this;
    }

    public function priceMode(): string
    {
        return $this->priceMode;
    }

    public function today(): CarbonImmutable
    {
        return $this->today;
    }

    /**
     * Carrega o espelho das fichas (todas ou só destes artigos) e as compras dos componentes.
     * $extraComponents: componentes de um rascunho (Fatia 4), que ainda não estão em nenhuma ficha.
     */
    public function load(?array $productIds = null, array $extraComponents = []): self
    {
        $extraComponents = array_values(array_unique(array_filter(array_map('strval', $extraComponents))));
        $boms = PingwinProductBom::where('company_id', $this->companyId)
            ->when($productIds !== null, fn ($q) => $q->whereIn('product_pingwin_id', array_merge($productIds, $extraComponents)))
            ->get();
        $this->addBoms($boms->all());

        // Semi-produtos: carrega as fichas dos componentes com ficha, até 5 níveis.
        $seen = array_fill_keys(array_keys($this->versions), true);
        for ($depth = 0; $depth < self::MAX_DEPTH; $depth++) {
            $next = [];
            foreach ($this->versions as $versions) {
                foreach ($versions as $v) {
                    foreach ($v['lines'] as $l) {
                        if ($l['is_bom'] && $l['component_id'] && ! isset($seen[$l['component_id']])) {
                            $next[$l['component_id']] = true;
                        }
                    }
                }
            }
            if (! $next) {
                break;
            }
            foreach (array_keys($next) as $id) {
                $seen[$id] = true;
            }
            $this->addBoms(PingwinProductBom::where('company_id', $this->companyId)->whereIn('product_pingwin_id', array_keys($next))->get()->all());
        }

        $components = array_fill_keys($extraComponents, true);
        foreach ($this->versions as $versions) {
            foreach ($versions as $v) {
                foreach ($v['lines'] as $l) {
                    if ($l['component_id']) {
                        $components[$l['component_id']] = true;
                    }
                }
            }
        }
        $ids = array_values(array_unique(array_merge(array_keys($components), array_keys($this->versions))));
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (PingwinCatalogItem::where('company_id', $this->companyId)->whereIn('pingwin_id', $chunk)->get() as $item) {
                $this->items[(string) $item->pingwin_id] = $item;
            }
        }
        $this->units = PingwinUnit::where('company_id', $this->companyId)->get()->keyBy(fn ($u) => (string) $u->pingwin_id)->all();
        $componentIds = array_map('strval', array_keys($components));
        $this->ensureConversions($componentIds);
        $bomIds = [];
        foreach ($this->versions as $versions) {
            foreach ($versions as $v) {
                $bomIds[] = $v['bom_id'];
                foreach ($v['lines'] as $l) {
                    if ($l['is_bom'] && $l['component_id']) {
                        $this->semiIds[$l['component_id']] = true;
                    }
                }
            }
        }
        foreach (array_chunk($bomIds, 500) as $chunk) {
            foreach (ProductBomYield::where('company_id', $this->companyId)->where('source', 'confirmado')
                ->whereIn('bom_pingwin_id', $chunk)->with('confirmer:id,name')->get() as $y) {
                $this->yields[(string) $y->bom_pingwin_id] = $y;
            }
        }
        $this->loadPurchases($componentIds);

        return $this;
    }

    /** @param list<PingwinProductBom> $boms */
    private function addBoms(array $boms): void
    {
        if (! $boms) {
            return;
        }
        $bomIds = array_map(fn ($b) => (string) $b->bom_pingwin_id, $boms);
        $stores = PingwinProductBomStore::where('company_id', $this->companyId)->whereIn('bom_pingwin_id', $bomIds)->get()->groupBy('bom_pingwin_id');
        $lines = PingwinProductBomLine::where('company_id', $this->companyId)->whereIn('bom_pingwin_id', $bomIds)->orderBy('line_key')->get();
        $costs = PingwinProductBomLineCost::whereIn('bom_line_id', $lines->pluck('id'))->get()->groupBy('bom_line_id');
        $linesByBom = $lines->groupBy('bom_pingwin_id');

        foreach ($boms as $b) {
            $this->versions[(string) $b->product_pingwin_id][] = [
                'bom_id' => (string) $b->bom_pingwin_id,
                'key' => (string) $b->version_key,
                'valid_from' => $b->valid_from?->toDateString(),
                'deleted' => (bool) $b->deleted,
                'stores' => ($stores[$b->bom_pingwin_id] ?? collect())->map(fn ($s) => [
                    'store_id' => (string) $s->store_pingwin_id, 'code' => $s->store_code, 'name' => $s->store_name, 'active' => (bool) $s->active,
                ])->values()->all(),
                'lines' => ($linesByBom[$b->bom_pingwin_id] ?? collect())->map(function ($l) use ($costs) {
                    $byWh = ($costs[$l->id] ?? collect())->mapWithKeys(fn ($c) => [(string) $c->warehouse_pingwin_id => ['unit_cost' => $c->unit_cost, 'cost' => $c->cost]])->all();

                    return [
                        'key' => (string) $l->line_key, 'component_id' => (string) $l->component_pingwin_id,
                        'code' => $l->component_code, 'description' => $l->component_description,
                        'unit_id' => (string) $l->unit_pingwin_id, 'unit' => $l->unit_desc,
                        'qnt' => (float) $l->qnt, 'qnt_base' => (float) $l->qnt_base,
                        'waste' => (float) $l->waste_pct, 'recovery' => (float) $l->recovery_pct,
                        'is_bom' => (bool) $l->is_bom, 'status' => $l->component_status,
                        'pingwin' => $byWh,
                    ];
                })->values()->all(),
            ];
        }
    }

    /** As compras dos componentes: as dos últimos 90 dias e, para os outros, a última conhecida. */
    private function loadPurchases(array $componentIds): void
    {
        $since = $this->today->subDays(self::WINDOW_DAYS)->toDateString();
        $rows = $this->purchaseLines($componentIds);
        foreach ($rows as $pid => $list) {
            foreach ($list as $row) {
                if ($row['date'] >= $since && $row['unit_id'] !== '') {
                    $this->purchaseUnits[$pid][$row['unit_id']] = $row['unit_id'];
                }
            }
        }
        foreach ($componentIds as $id) {
            $list = $rows[$id] ?? [];
            $this->purchaseRows[$id] = $list;
            // Para o custo, só a quantidade estrita (sem o fator do PingWin): as linhas sem conversão ficam de fora.
            $strict = array_map(fn ($r) => ['qnt_base' => (float) ($r['strict_qnt_base'] ?? 0.0)] + $r, $list);
            $stats = self::purchaseStats($strict, $this->today);
            $recent = array_filter($list, fn ($r) => $r['date'] >= $since && $r['total'] > 0);
            $unconverted = array_filter($recent, fn ($r) => $r['strict_qnt_base'] === null);
            if ($stats['status'] === 'sem_custo' && array_filter($list, fn ($r) => $r['strict_qnt_base'] === null && $r['total'] > 0)) {
                $stats['status'] = 'sem_conversao';
                $stats['unit_label'] = (string) (end($unconverted) ?: end($list))['unit_label'];
            }
            $stats['unconverted'] = count($unconverted);
            $stats['header_discounts'] = count(array_unique(array_column(array_filter($recent, fn ($r) => $r['header_discount']), 'docheader_id')));
            $this->purchase[$id] = $stats + ['qty_source' => self::qtySource(array_filter($list, fn ($r) => $r['strict_qnt_base'] !== null), $since)];
        }
    }

    /**
     * As linhas de compra (faturas e faturas-recibo fechadas) destes artigos ou, sem lista, de todos,
     * com a quantidade na unidade base: o fator confirmado (do fornecedor, senão o geral) manda sobre
     * a qnt_base do raw. Também a usa a Auditoria (preço fora do normal, unidade sem conversão).
     *
     * @param  list<string>|null  $productIds
     * @return array<string, list<array<string, mixed>>>  por artigo, por data
     */
    public function purchaseLines(?array $productIds, ?string $since = null): array
    {
        $this->ensureUnits();
        $rows = [];
        $chunks = $productIds === null ? [null] : array_chunk(array_values(array_unique(array_map('strval', $productIds))), 500);
        foreach ($chunks as $chunk) {
            $q = DB::table('pingwin_supplier_document_lines as l')
                ->join('pingwin_supplier_documents as d', fn ($j) => $j->on('d.docheader_id', '=', 'l.docheader_id')->on('d.company_id', '=', 'l.company_id'))
                ->where('l.company_id', $this->companyId)
                ->when($chunk !== null, fn ($q) => $q->whereIn('l.product_pingwin_id', $chunk))
                ->whereIn('d.docconfig_id', self::PURCHASE_DOCCONFIGS)
                ->where('d.docstatus_id', SupplierDocumentLinesService::STATUS_CLOSED)
                ->where('d.doc_date', '<=', $this->today->toDateString())
                ->when($since !== null, fn ($q) => $q->where('d.doc_date', '>=', $since))
                ->orderBy('d.doc_date')->orderBy('l.id')
                ->get(['l.id', 'l.product_pingwin_id', 'l.qnt', 'l.price', 'l.total_cents', 'l.unit_desc', 'l.description', 'l.raw', 'l.docheader_id',
                    'd.doc_date', 'd.document', 'd.tax_number', 'd.entity_name', 'd.entity_pingwin_id', 'd.discount1', 'd.discount2']);
            $this->ensureConversions($q->pluck('product_pingwin_id')->map(fn ($v) => (string) $v)->unique()->values()->all());
            foreach ($q as $r) {
                $raw = is_string($r->raw) ? (json_decode($r->raw, true) ?: []) : (array) $r->raw;
                $qnt = (float) $r->qnt;
                $qb = $raw['qnt_base'] ?? null;
                if (! is_numeric($qb)) {
                    $qb = $qnt * (is_numeric($raw['unit_value'] ?? null) ? (float) $raw['unit_value'] : 1.0);
                }
                $pid = (string) $r->product_pingwin_id;
                $unitId = (string) ($raw['unit_id'] ?? '');
                $unitKey = $unitId !== '' ? $unitId : (string) ($r->unit_desc ?? '');
                $nif = SupplierArticleMapService::nif($r->tax_number);
                $row = [
                    'id' => (int) $r->id, 'date' => substr((string) $r->doc_date, 0, 10), 'total' => ((int) $r->total_cents) / 100,
                    'qnt' => $qnt, 'raw_qnt_base' => (float) $qb, 'qnt_base' => (float) $qb, 'qty_source' => 'raw',
                    'unit_key' => $unitKey, 'unit_id' => $unitId, 'unit_label' => (string) ($r->unit_desc ?? $unitKey), 'nif' => $nif,
                    'supplier' => $r->entity_name, 'supplier_id' => (string) ($r->entity_pingwin_id ?? ''), 'description' => $r->description,
                    'price' => (float) $r->price, 'docheader_id' => (string) $r->docheader_id, 'document' => $r->document,
                    'header_discount' => abs((float) ($r->discount1 ?? 0)) > 1e-9 || abs((float) ($r->discount2 ?? 0)) > 1e-9,
                ];
                // O fator confirmado (do fornecedor, senão o geral) manda sobre a qnt_base do raw.
                $conv = $this->conversions[$pid]["{$unitKey}|{$nif}"] ?? $this->conversions[$pid]["{$unitKey}|"] ?? null;
                if ($conv && $qnt > 0) {
                    $row['qnt_base'] = $qnt * $conv->factor_to_base;
                    $row['qty_source'] = 'confirmado';
                }
                // A quantidade para o custo (Fatia 3): nunca o fator do PingWin.
                $row['strict_qnt_base'] = $row['qty_source'] === 'confirmado' ? $row['qnt_base']
                    : ($this->isBaseUnit($pid, $unitId, $qnt, (float) $qb) ? $qnt : null);
                $rows[$pid][] = $row;
            }
        }

        return $rows;
    }

    /**
     * A linha de compra está na unidade base do artigo (a quantidade da linha já é a base): a unidade
     * é de topo (sem pai) e da família da unidade base do artigo. Unidade desconhecida: só se o PingWin
     * não lhe aplicou fator (qnt_base = qnt).
     */
    public function isBaseUnit(string $productId, string $unitId, float $qnt, float $rawQntBase): bool
    {
        $u = $unitId !== '' ? ($this->units[$unitId] ?? null) : null;
        if (! $u) {
            return abs($rawQntBase - $qnt) < 1e-9;
        }
        if ($u->parent_pingwin_id) {
            return false;
        }
        $item = $this->items[$productId] ?? null;
        $itemRoot = $item?->base_unit_id ? $this->unitRoot((string) $item->base_unit_id) : null;

        return $itemRoot === null || $itemRoot === $unitId;
    }

    /** As unidades do PingWin (uma vez). */
    private function ensureUnits(): void
    {
        if (! $this->units) {
            $this->units = PingwinUnit::where('company_id', $this->companyId)->get()->keyBy(fn ($u) => (string) $u->pingwin_id)->all();
        }
    }

    /** Os fatores confirmados destes artigos (os que ainda não estão carregados). */
    private function ensureConversions(array $productIds): void
    {
        $missing = array_values(array_filter(array_map('strval', $productIds), fn ($id) => ! isset($this->conversionsLoaded[$id])));
        foreach (array_chunk($missing, 500) as $chunk) {
            foreach (ProductUnitConversion::where('company_id', $this->companyId)->where('source', 'confirmado')
                ->whereIn('product_pingwin_id', $chunk)->with('confirmer:id,name')->get() as $c) {
                $this->conversions[(string) $c->product_pingwin_id][$c->unit_key . '|' . $c->supplier_nif] = $c;
            }
            foreach ($chunk as $id) {
                $this->conversionsLoaded[$id] = true;
            }
        }
    }

    /** Os artigos do catálogo (os que ainda não estão carregados). */
    public function ensureItems(array $productIds): void
    {
        $missing = array_values(array_filter(array_map('strval', $productIds), fn ($id) => ! isset($this->items[$id])));
        foreach (array_chunk($missing, 500) as $chunk) {
            foreach (PingwinCatalogItem::where('company_id', $this->companyId)->whereIn('pingwin_id', $chunk)->get() as $item) {
                $this->items[(string) $item->pingwin_id] = $item;
            }
        }
    }

    /** As unidades do PingWin por id. */
    public function units(): array
    {
        $this->ensureUnits();

        return $this->units;
    }

    /**
     * O custo de compra por unidade base, a partir das linhas [{id, date, total (€ sem IVA, já com
     * descontos), qnt_base}].
     *
     * @return array{status:string, last:?float, avg:?float, last_date:?string, lines:int, outliers:int}
     */
    public static function purchaseStats(array $lines, CarbonInterface $today): array
    {
        $since = CarbonImmutable::parse($today->toDateString())->subDays(self::WINDOW_DAYS)->toDateString();
        $valid = array_values(array_filter($lines, fn ($l) => (float) $l['qnt_base'] > 0));
        usort($valid, fn ($a, $b) => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);
        $recent = array_values(array_filter($valid, fn ($l) => $l['date'] >= $since));

        if ($recent) {
            $prices = array_map(fn ($l) => $l['total'] / $l['qnt_base'], $recent);
            $median = self::median($prices);
            $keep = [];
            foreach ($recent as $i => $l) {
                $p = $prices[$i];
                if ($median > 0 && ($p > $median * self::OUTLIER_FACTOR || $p < $median / self::OUTLIER_FACTOR)) {
                    continue;
                }
                $keep[] = $l;
            }
            $qty = array_sum(array_map(fn ($l) => $l['qnt_base'], $keep));
            $total = array_sum(array_map(fn ($l) => $l['total'], $keep));
            $last = end($keep) ?: null;

            return [
                'status' => $qty > 0 && $total > 0 ? 'ok' : 'sem_custo',
                'last' => $last ? round($last['total'] / $last['qnt_base'], 6) : null,
                'avg' => $qty > 0 ? round($total / $qty, 6) : null,
                'last_date' => $last['date'] ?? null,
                'lines' => count($keep),
                'outliers' => count($recent) - count($keep),
            ];
        }

        $old = array_values(array_filter($valid, fn ($l) => $l['total'] > 0));
        if ($old) {
            $l = end($old);
            $p = round($l['total'] / $l['qnt_base'], 6);

            return ['status' => 'antigo', 'last' => $p, 'avg' => $p, 'last_date' => $l['date'], 'lines' => 0, 'outliers' => 0];
        }

        return ['status' => 'sem_custo', 'last' => null, 'avg' => null, 'last_date' => null, 'lines' => 0, 'outliers' => 0];
    }

    /** De onde veio a quantidade das compras usadas: confirmado, raw ou misto. */
    private static function qtySource(array $rows, string $since): ?string
    {
        $used = array_filter($rows, fn ($r) => $r['date'] >= $since) ?: $rows;
        $sources = array_values(array_unique(array_column($used, 'qty_source')));

        return match (count($sources)) {
            0 => null,
            1 => $sources[0],
            default => 'misto',
        };
    }

    public static function median(array $values): float
    {
        sort($values);
        $n = count($values);
        if ($n === 0) {
            return 0.0;
        }

        return $n % 2 ? (float) $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
    }

    /**
     * A versão em vigor na data e na loja (sem loja: com alguma loja ativa), ou null.
     *
     * @param  list<array{key:string, valid_from:?string, deleted:bool, stores:list<array{store_id:string, active:bool}>}>  $versions
     */
    public static function versionInForce(array $versions, string $date, ?string $storeId = null): ?array
    {
        $best = null;
        foreach ($versions as $v) {
            if ($v['deleted'] || ! $v['valid_from'] || $v['valid_from'] > $date) {
                continue;
            }
            $active = array_filter($v['stores'], fn ($s) => $s['active'] && ($storeId === null || $s['store_id'] === $storeId));
            if (! $active) {
                continue;
            }
            if ($best === null || [$v['valid_from'], $v['key']] > [$best['valid_from'], $best['key']]) {
                $best = $v;
            }
        }

        return $best;
    }

    /** Auditoria v2 (Unidades): todas as versões carregadas, por artigo. */
    public function allVersions(): array
    {
        return $this->versions;
    }

    /** Auditoria v2 (Unidades): quantas unidades de topo vale uma unidade (o unit_value ao longo dos pais). */
    public function unitFactor(string $unitId): ?float
    {
        return $this->factorToRoot($unitId);
    }

    /** @return list<array> as versões do artigo no espelho (vazio = sem ficha) */
    public function versionsOf(string $productId): array
    {
        return $this->versions[$productId] ?? [];
    }

    public function item(string $productId): ?PingwinCatalogItem
    {
        return $this->items[$productId] ?? null;
    }

    /** As linhas de compra carregadas de um componente (com a quantidade estrita e de onde veio). */
    public function purchaseRowsOf(string $componentId): array
    {
        return $this->purchaseRows[$componentId] ?? [];
    }

    public function purchaseOf(string $componentId): array
    {
        return $this->purchase[$componentId] ?? ['status' => 'sem_custo', 'last' => null, 'avg' => null, 'last_date' => null, 'lines' => 0, 'outliers' => 0];
    }

    /**
     * O custo de uma versão da ficha: as linhas com o custo XPLENDOR e o do PingWin, o total e os
     * avisos. $path = os artigos acima (ciclos); $depth = nível do semi-produto.
     */
    public function costVersion(array $version, string $productId, int $depth = 0, array $path = []): array
    {
        $path[] = $productId;
        $lines = [];
        $cost = 0.0;
        $pingwin = 0.0;
        $complete = true;
        $anyCost = false;
        $warnings = [];

        $lowConfidence = false;
        $needsFactor = false;
        foreach ($version['lines'] as $l) {
            $row = $this->costLine($l, $depth, $path);
            $lines[] = $row;
            $lowConfidence = $lowConfidence || $row['low_confidence'];
            $needsFactor = $needsFactor || $row['needs_factor'] || ($row['sub']['needs_factor'] ?? false);
            $pingwin += (float) ($row['pingwin_cost'] ?? 0);
            if ($row['line_cost'] === null || $row['cost_status'] === 'parcial') {
                $complete = false;
            }
            if ($row['line_cost'] !== null) {
                $cost += $row['line_cost'];
                $anyCost = true;
            }
            foreach ($row['warnings'] as $w) {
                $warnings[] = $w + ['line' => $row['code'] ?? $row['description'], 'component_id' => $row['component_id'] ?? null];
            }
        }
        if (! $version['lines']) {
            $complete = false;
            $warnings[] = ['type' => 'empty_version', 'text' => 'A versão em vigor não tem linhas.'];
        }

        $diff = ($pingwin > 0 && $complete) ? ($cost - $pingwin) / $pingwin : null;
        if ($diff !== null && abs($diff) > self::PINGWIN_DIFF) {
            $warnings[] = ['type' => 'pingwin_diff', 'text' => sprintf('O custo XPLENDOR difere %s%% do custo PingWin.', self::pct(abs($diff)))];
        }

        return [
            'lines' => $lines,
            // Sem nenhuma linha com custo: null (não 0). Com algumas: o parcial (complete false).
            'cost' => $anyCost ? round($cost, 6) : null,
            'complete' => $complete,
            'pingwin_cost' => round($pingwin, 6),
            'diff_pct' => $diff !== null ? round($diff * 100, 1) + 0.0 : null,
            'low_confidence' => $lowConfidence,
            'needs_factor' => $needsFactor,
            'warnings' => $warnings,
        ];
    }

    private function costLine(array $l, int $depth, array $path): array
    {
        $cid = $l['component_id'];
        $warnings = [];
        $sub = null;
        $source = 'compras';
        $lowConfidence = false;

        if ($l['is_bom'] && $this->versionsOf($cid)) {
            $source = 'ficha';
            if (in_array($cid, $path, true)) {
                $unit = ['status' => 'ciclo', 'last' => null, 'avg' => null, 'last_date' => null, 'lines' => 0, 'outliers' => 0];
                $warnings[] = ['type' => 'cycle', 'text' => 'Ciclo: o semi-produto usa-se a si próprio (direta ou indiretamente).'];
            } elseif ($depth + 1 > self::MAX_DEPTH) {
                $unit = ['status' => 'fundo', 'last' => null, 'avg' => null, 'last_date' => null, 'lines' => 0, 'outliers' => 0];
                $warnings[] = ['type' => 'depth', 'text' => 'Semi-produtos com mais de ' . self::MAX_DEPTH . ' níveis: o custo não foi calculado.'];
            } else {
                // Custo parcial do semi-produto (com linhas sem custo) conta, marcado como parcial.
                // Por unidade base: custo do lote ÷ rendimento confirmado (sem rendimento: ÷ 1).
                $sub = $this->semiProduct($cid, $depth + 1, $path);
                $value = $sub['cost_per_unit'] ?? null;
                $status = $value === null ? 'sem_custo' : ($sub['complete'] ? 'ok' : 'parcial');
                $unit = ['status' => $status, 'last' => $value, 'avg' => $value, 'last_date' => null, 'lines' => 0, 'outliers' => 0];
                if ($sub && ! $sub['yield']['confirmed']) {
                    $lowConfidence = true;
                    $warnings[] = ['type' => 'yield_unconfirmed', 'text' => 'Rendimento do semi-produto por confirmar: o custo desta linha é de baixa confiança.'];
                } elseif ($sub && $sub['low_confidence']) {
                    $lowConfidence = true;
                }
            }
        } else {
            $unit = $this->purchaseOf($cid);
            // Sem compras (ou só compras a 0 €) e com custo PingWin 0 (aparas, ossos): custo 0, só informativo.
            $pw0 = $l['pingwin']['']['cost'] ?? null;
            $paid = array_filter($this->purchaseRows[$cid] ?? [], fn ($r) => $r['total'] > 0);
            if ($unit['status'] === 'sem_custo' && ! $paid && ($pw0 === null || (float) $pw0 === 0.0)) {
                $unit = ['status' => 'zero', 'last' => 0.0, 'avg' => 0.0, 'last_date' => null, 'lines' => 0, 'outliers' => 0, 'qty_source' => null];
            }
        }

        $unitPrice = $this->priceMode === 'ultimo' ? ($unit['last'] ?? $unit['avg']) : $unit['avg'];
        $lineCost = $unitPrice !== null ? round($l['qnt_base'] * $unitPrice * (1 + $l['waste'] / 100), 6) : null;
        $pw = $l['pingwin'][''] ?? ['unit_cost' => null, 'cost' => null];

        $similar = null;
        if ($unit['status'] === 'zero') {
            $warnings[] = ['type' => 'zero_cost', 'level' => 'info', 'text' => 'Componente a custo 0 (sem compras pagas e custo PingWin 0): pode ser um subproduto.'];
        } elseif ($unit['status'] === 'sem_custo' && $source === 'compras' && ($similar = $this->similarPurchased($cid, (string) $l['description']))) {
            $warnings[] = ['type' => 'similar', 'text' => "Componente sem compras; compra-se um parecido: {$similar['code']} {$similar['description']}."];
        }
        if ($unit['status'] === 'sem_conversao') {
            $warnings[] = ['type' => 'no_conversion', 'text' => sprintf('Compras em «%s» sem fator confirmado na XPLENDOR: sem preço.', $unit['unit_label'] ?? 'outra unidade')];
        } elseif (($unit['unconverted'] ?? 0) > 0) {
            $warnings[] = ['type' => 'unconverted', 'level' => 'info', 'text' => sprintf('%d compra(s) noutra unidade, sem fator confirmado, ficaram de fora do preço.', $unit['unconverted'])];
        }
        if (($unit['header_discounts'] ?? 0) > 0) {
            $warnings[] = ['type' => 'header_discount', 'level' => 'info', 'text' => sprintf('%d fatura(s) com desconto no cabeçalho: o desconto não está repartido pelas linhas (o preço pode estar acima do real).', $unit['header_discounts'])];
        }
        if ($unit['status'] === 'sem_custo') {
            $warnings[] = ['type' => 'no_cost', 'text' => $source === 'ficha' ? 'O semi-produto não tem custo.' : 'Componente sem compras: sem custo.'];
        } elseif ($unit['status'] === 'parcial') {
            $warnings[] = ['type' => 'no_cost', 'text' => 'O semi-produto tem linhas sem custo: o custo é parcial.'];
        } elseif ($unit['status'] === 'antigo') {
            $warnings[] = ['type' => 'old_cost', 'text' => 'Sem compras nos últimos 90 dias: usado o último preço conhecido.'];
        }
        if (($unit['outliers'] ?? 0) > 0) {
            $warnings[] = ['type' => 'outliers', 'text' => sprintf('%d compra(s) com preço fora do normal ficaram de fora.', $unit['outliers'])];
        }
        $status = $l['status'];
        $item = $this->item($cid);
        if ($status === 2 || ($item && ! $item->is_active)) {
            $warnings[] = ['type' => 'inactive', 'text' => 'Componente inativo no PingWin.'];
        }
        $diff = ($lineCost !== null && ($pw['cost'] ?? 0) > 0) ? ($lineCost - $pw['cost']) / $pw['cost'] : null;
        $unitWarning = false;
        if ($u = $this->suspiciousUnit($cid, $l['unit_id'])) {
            $unitWarning = true;
            $warnings[] = ['type' => 'unit_factor', 'text' => "Unidade «{$u}» com fator 1: o custo por unidade pode estar errado."];
        } elseif ($source === 'compras' && ($diff === null || abs($diff) > self::PINGWIN_DIFF)
            && ($u = $this->foreignPurchaseUnit($cid, $l['unit_id']))) {
            // Só quando o custo não bate com o do PingWin (se bater, a unidade da compra está certa).
            $unitWarning = true;
            $warnings[] = ['type' => 'unit_factor', 'text' => "Compras em «{$u}» e ficha em «{$l['unit']}»: o custo por unidade pode estar errado."];
        }

        // Fator por confirmar: componente com compras, sem fator confirmado na unidade principal, e
        // com aviso de unidade ou com diferença para o PingWin que um fator sugerido explica.
        $conversion = $source === 'compras' ? $this->conversionBlock($l, $pw) : null;
        $needsFactor = $conversion !== null && $conversion['confirmed'] === null
            && ($unitWarning || $unit['status'] === 'sem_conversao' || ($diff !== null && abs($diff) > self::PINGWIN_DIFF && $conversion['suggestions']));
        if ($needsFactor) {
            $warnings[] = ['type' => 'factor_unconfirmed', 'text' => "Fator por confirmar: quantas unidades base vale 1 «{$conversion['unit_label']}» na compra."];
        }

        return [
            'key' => $l['key'], 'component_id' => $cid, 'code' => $l['code'], 'description' => $l['description'],
            'qnt' => $l['qnt'], 'unit' => $l['unit'], 'unit_id' => $l['unit_id'], 'qnt_base' => $l['qnt_base'],
            'waste' => $l['waste'], 'recovery' => $l['recovery'], 'is_bom' => $l['is_bom'], 'status' => $status,
            'cost_source' => $source,
            'cost_status' => $unit['status'],
            'unit_cost_last' => $unit['last'], 'unit_cost_avg' => $unit['avg'], 'last_purchase' => $unit['last_date'],
            'unit_cost' => $unitPrice, 'purchase_lines' => $unit['lines'] ?? 0,
            'pingwin_unit_cost' => $pw['unit_cost'] ?? null, 'pingwin_cost' => $pw['cost'] ?? null,
            'line_cost' => $lineCost,
            'diff_pct' => $diff !== null ? round($diff * 100, 1) + 0.0 : null,
            'pingwin_by_warehouse' => array_values(array_map(fn ($wh, $c) => ['warehouse_id' => (string) $wh, 'unit_cost' => $c['unit_cost'], 'cost' => $c['cost']],
                array_keys($l['pingwin']), array_values($l['pingwin']))),
            'qty_source' => $source === 'compras' ? ($unit['qty_source'] ?? null) : null,
            'low_confidence' => $lowConfidence,
            'needs_factor' => $needsFactor,
            'conversion' => $conversion,
            'similar' => $similar,
            'warnings' => $warnings,
            'sub' => $sub,
        ];
    }

    /** A ficha em vigor de um semi-produto (em qualquer loja), com o custo, ou null se não houver. */
    private function semiProduct(string $productId, int $depth, array $path): ?array
    {
        $memoKey = $productId . '|' . implode('>', $path);
        if (isset($this->memo[$memoKey])) {
            return $this->memo[$memoKey];
        }
        $v = self::versionInForce($this->versionsOf($productId), $this->today->toDateString());
        if (! $v) {
            return $this->memo[$memoKey] = null;
        }
        $res = $this->costVersion($v, $productId, $depth, $path);
        $yield = $this->yieldInfo($productId, $v);
        $divisor = $yield['confirmed'] ? $yield['quantity'] : 1.0;

        return $this->memo[$memoKey] = $res + [
            'version' => self::versionInfo($v), 'product_id' => $productId, 'yield' => $yield,
            'cost_per_unit' => $res['cost'] !== null && $divisor > 0 ? round($res['cost'] / $divisor, 6) : null,
        ];
    }

    /** O nome da unidade suspeita (embalagem com fator 1) usada na linha, na compra ou no artigo. */
    public function suspiciousUnit(string $componentId, ?string $lineUnitId): ?string
    {
        $item = $this->item($componentId);
        $ids = array_filter(array_merge(
            [$lineUnitId],
            $item ? [$item->default_purchase_unit_id, $item->default_stock_unit_id] : [],
            array_values($this->purchaseUnits[$componentId] ?? []),
        ));
        foreach (array_unique($ids) as $id) {
            $u = $this->units[(string) $id] ?? null;
            if (! $u || ! $u->parent_pingwin_id || abs((float) $u->unit_value - 1.0) > 1e-9) {
                continue;
            }
            $name = trim(($u->description ?? '') . ' ' . ($u->shortname ?? ''));
            if (preg_match(self::PACK_NAME, $name)) {
                return (string) $u->description;
            }
        }

        return null;
    }

    /** A unidade das compras (90 dias) de outra família que a da linha da ficha (ex.: Unidade vs Litro). */
    public function foreignPurchaseUnit(string $componentId, ?string $lineUnitId): ?string
    {
        $root = $this->unitRoot($lineUnitId);
        if ($root === null) {
            return null;
        }
        foreach ($this->purchaseUnits[$componentId] ?? [] as $id) {
            $other = $this->unitRoot($id);
            if ($other !== null && $other !== $root) {
                return (string) ($this->units[$id]->description ?? $id);
            }
        }

        return null;
    }

    /** A unidade de topo (sem pai) de uma unidade, ou null se não for conhecida. */
    public function unitRoot(?string $unitId): ?string
    {
        $id = (string) $unitId;
        for ($i = 0; $i < 6 && $id !== ''; $i++) {
            $u = $this->units[$id] ?? null;
            if (! $u) {
                return null;
            }
            if (! $u->parent_pingwin_id) {
                return $id;
            }
            $id = (string) $u->parent_pingwin_id;
        }

        return null;
    }

    /**
     * A unidade de topo (família) de um componente: a unidade base do artigo; senão a unidade de
     * compra ou de venda pelo nome (o catálogo traz o rótulo); senão a da linha; senão Unidade.
     */
    public function componentBaseRoot(string $componentId, ?string $lineUnitId = null): ?string
    {
        $item = $this->item($componentId);
        $byLabel = function (?string $label) {
            $label = mb_strtolower(trim((string) $label));
            if ($label === '') {
                return null;
            }
            foreach ($this->units as $id => $u) {
                if (mb_strtolower((string) $u->description) === $label) {
                    return $this->unitRoot((string) $id);
                }
            }

            return null;
        };

        return $this->unitRoot((string) $item?->base_unit_id)
            ?? $byLabel($item?->purchaseunit) ?? $byLabel($item?->saleunit)
            ?? $this->unitRoot($lineUnitId)
            ?? (isset($this->units['11001']) ? '11001' : null);
    }

    /**
     * As unidades possíveis de um componente na ficha: as da família da unidade base (como as
     * lkprodunits do PingWin), com quantas unidades base vale cada uma (unit_value ao longo dos pais).
     *
     * @return list<array{id:string, label:string, short:?string, factor:float, base:bool, suspicious:bool}>
     */
    public function unitsFor(string $componentId, ?string $lineUnitId = null): array
    {
        $root = $this->componentBaseRoot($componentId, $lineUnitId);
        if ($root === null) {
            return [];
        }
        $out = [];
        foreach ($this->units as $id => $u) {
            $id = (string) $id;
            if (! $u->is_active && $id !== $lineUnitId && $id !== $root) {
                continue;
            }
            if ($this->unitRoot($id) !== $root) {
                continue;
            }
            $f = $this->factorToRoot($id) ?? 1.0;
            $name = trim(($u->description ?? '') . ' ' . ($u->shortname ?? ''));
            $out[] = [
                'id' => $id, 'label' => (string) $u->description, 'short' => $u->shortname, 'factor' => $f, 'base' => $id === $root,
                'suspicious' => $u->parent_pingwin_id && abs($f - 1.0) < 1e-9 && (bool) preg_match(self::PACK_NAME, $name),
            ];
        }
        usort($out, fn ($a, $b) => [! $a['base'], $a['factor'], $a['label']] <=> [! $b['base'], $b['factor'], $b['label']]);

        return $out;
    }

    /**
     * O custo de um rascunho (Fatia 4): as linhas [{key?, component_id, unit_id, qnt, waste,
     * recovery}] com a quantidade base (qnt × fator da unidade), como uma versão da ficha. As linhas
     * que já existiam (key) trazem o custo PingWin da versão de origem.
     */
    public function costDraft(string $productId, array $lines, ?array $baseVersion = null): array
    {
        $baseLines = [];
        foreach ($baseVersion['lines'] ?? [] as $l) {
            $baseLines[$l['key']] = $l;
        }
        $synthetic = [];
        foreach (array_values($lines) as $i => $l) {
            $cid = (string) ($l['component_id'] ?? '');
            $item = $this->item($cid);
            $unitId = (string) ($l['unit_id'] ?? '');
            $factor = $this->factorToRoot($unitId) ?? 1.0;
            $base = isset($l['key']) && $l['key'] !== null ? ($baseLines[$l['key']] ?? null) : null;
            $samePingwin = $base && $base['component_id'] === $cid && $base['unit_id'] === $unitId;
            $synthetic[] = [
                'key' => (string) ($l['key'] ?? sprintf('novo-%02d', $i)),
                'component_id' => $cid,
                'code' => $item?->code ? ltrim((string) $item->code, "\u{FEFF}") : ($base['code'] ?? null),
                'description' => $item?->description ?? ($l['description'] ?? $base['description'] ?? $cid),
                'unit_id' => $unitId,
                'unit' => $this->units[$unitId]->description ?? ($base['unit'] ?? null),
                'qnt' => (float) ($l['qnt'] ?? 0),
                'qnt_base' => round((float) ($l['qnt'] ?? 0) * $factor, 6),
                'waste' => (float) ($l['waste'] ?? 0),
                'recovery' => (float) ($l['recovery'] ?? 0),
                'is_bom' => (bool) $this->versionsOf($cid),
                'status' => $item && ($item->product_status === 'Inativo' || ! $item->is_active) ? 2 : ($base['status'] ?? 1),
                // O custo PingWin só vale para a linha como estava (mesmo componente e unidade).
                'pingwin' => $samePingwin ? $base['pingwin'] : [],
            ];
        }

        return $this->costVersion(['bom_id' => 'rascunho', 'key' => '', 'valid_from' => null, 'deleted' => false, 'stores' => [], 'lines' => $synthetic], $productId);
    }

    /** É semi-produto: usado como componente com ficha noutra ficha, ou artigo de produção. */
    public function isSemi(string $productId): bool
    {
        return isset($this->semiIds[$productId]) || (bool) ($this->item($productId)?->forproduction);
    }

    /**
     * O rendimento de uma versão da ficha: o confirmado (com quem e quando) ou a sugestão, e se a
     * ficha parece ser de um lote. Sugestões, por esta ordem (nunca aplicadas sozinhas): a unidade
     * de venda/compra por omissão (BIDON 30L → 30 L), o nome ("30LT") e a soma das quantidades em
     * base (só quando todas as linhas são da família da unidade base).
     */
    public function yieldInfo(string $productId, array $version): array
    {
        $item = $this->item($productId);
        $baseId = (string) ($item?->base_unit_id ?? '');
        $base = $this->units[$baseId] ?? null;
        $baseLabel = $base?->description ?? 'unidade base';
        $family = $base ? RecipeHints::family($base->description, $base->shortname) : null;
        $baseRoot = $this->unitRoot($baseId);

        $suggestions = [];
        $add = function (float $q, string $reason) use (&$suggestions) {
            $q = RecipeHints::nice($q);
            if ($q <= 0) {
                return;
            }
            foreach ($suggestions as &$s) {
                if (abs($s['quantity'] - $q) < 1e-6) {
                    $s['reasons'][] = $reason;

                    return;
                }
            }
            $suggestions[] = ['quantity' => $q, 'reasons' => [$reason]];
        };
        foreach (array_unique(array_filter([$item?->default_sale_unit_id, $item?->default_purchase_unit_id])) as $uid) {
            $f = $this->factorToRoot((string) $uid);
            if ($f !== null && $f > 1 && $this->unitRoot((string) $uid) === $baseRoot) {
                $add($f, 'Unidade de venda ou compra por omissão: ' . ($this->units[$uid]->description ?? $uid));
            }
        }
        if ($family) {
            foreach (RecipeHints::quantitiesInText($item?->description, $family) as $q) {
                $add($q['quantity'], "Nome do artigo: «{$q['text']}»");
            }
        }
        // Soma das quantidades por família (para a sugestão e para o aviso de lote).
        $sums = [];
        $allCompatible = (bool) $version['lines'];
        foreach ($version['lines'] as $l) {
            $root = $this->unitRoot($l['unit_id']);
            $label = $root ? ($this->units[$root]->shortname ?: $this->units[$root]->description) : ($l['unit'] ?? '?');
            $sums[$root ?? ('?' . $label)] = ['label' => $label, 'quantity' => ($sums[$root ?? ('?' . $label)]['quantity'] ?? 0) + $l['qnt_base']];
            if ($root === null || $root !== $baseRoot) {
                $allCompatible = false;
            }
        }
        $sameFamily = $baseRoot !== null ? ($sums[$baseRoot]['quantity'] ?? 0.0) : 0.0;
        if ($allCompatible && $sameFamily > 0) {
            $add($sameFamily, "Soma das quantidades em {$baseLabel}");
        }

        $confirmed = $this->yields[$version['bom_id']] ?? null;
        $top = $suggestions[0] ?? null;
        // Parece um lote: os componentes da mesma família somam 3 ou mais unidades base, ou há uma
        // sugestão de 3 ou mais (ex.: BIDON 30L).
        $batch = ! $confirmed && $baseRoot !== null && ($sameFamily >= 3 || ($top['quantity'] ?? 0) >= 3);

        return [
            'confirmed' => (bool) $confirmed,
            'source' => $confirmed ? 'confirmado' : ($top ? 'sugerido' : null),
            'quantity' => $confirmed ? $confirmed->quantity : ($top['quantity'] ?? null),
            'unit_id' => $baseId ?: null,
            'unit_label' => $baseLabel,
            'reason' => $confirmed ? $confirmed->reason : ($top ? implode('; ', $top['reasons']) : null),
            'confirmed_by' => $confirmed?->confirmer?->name,
            'confirmed_at' => $confirmed?->confirmed_at?->toIso8601String(),
            'suggestions' => array_map(fn ($s) => ['quantity' => $s['quantity'], 'reason' => implode('; ', $s['reasons'])], $suggestions),
            'sums' => array_values(array_map(fn ($x) => ['label' => $x['label'], 'quantity' => round($x['quantity'], 3)], $sums)),
            'looks_like_batch' => $batch,
        ];
    }

    /** Quantas unidades de topo vale uma unidade (unit_value ao longo dos pais). */
    private function factorToRoot(string $unitId): ?float
    {
        $f = 1.0;
        $id = $unitId;
        for ($i = 0; $i < 6 && $id !== ''; $i++) {
            $u = $this->units[$id] ?? null;
            if (! $u) {
                return null;
            }
            if (! $u->parent_pingwin_id) {
                return $f;
            }
            $f *= (float) ($u->unit_value ?: 1);
            $id = (string) $u->parent_pingwin_id;
        }

        return null;
    }

    /**
     * Auditoria (unidade sem conversão): o bloco do "Confirmar fator" para um artigo comprado, esteja
     * ou não numa ficha, para a unidade pedida (senão a mais usada). Null se não houver compras.
     */
    public function conversionFor(string $productId, ?string $unitKey = null): ?array
    {
        $this->load([], [$productId]);
        $item = $this->item($productId);
        $baseId = (string) ($item?->base_unit_id ?: '11001');
        $root = $this->unitRoot($baseId) ?? $baseId;
        $line = [
            'component_id' => $productId, 'unit_id' => $root, 'unit' => $this->units[$root]->description ?? 'Unidade',
            'description' => $item?->description ?? $productId, 'qnt_base' => 1.0, 'waste' => 0.0,
        ];

        return $this->conversionBlock($line, [], $unitKey);
    }

    /**
     * Para a confirmação do fator de um componente comprado: a unidade de compra principal (a mais
     * usada), o fator de hoje (raw ou confirmado), o confirmado, e as sugestões (descrição, razão
     * entre o preço e o custo PingWin, unit_value > 1), cada uma com o efeito no custo.
     */
    private function conversionBlock(array $l, array $pw, ?string $forceUnit = null): ?array
    {
        $cid = $l['component_id'];
        $rows = $this->purchaseRows[$cid] ?? [];
        if (! $rows) {
            return null;
        }
        $since = $this->today->subDays(self::WINDOW_DAYS)->toDateString();
        $used = array_values(array_filter($rows, fn ($r) => $r['date'] >= $since)) ?: $rows;
        // Auditoria: a unidade em causa, mesmo que não seja a mais usada (nem dos últimos 90 dias).
        if ($forceUnit !== null && ! in_array($forceUnit, array_column($used, 'unit_key'), true)) {
            $used = $rows;
        }
        $byUnit = [];
        foreach ($used as $r) {
            $byUnit[$r['unit_key']][] = $r;
        }
        uasort($byUnit, fn ($a, $b) => count($b) <=> count($a));
        $unitKey = $forceUnit !== null && isset($byUnit[$forceUnit]) ? $forceUnit : (string) array_key_first($byUnit);
        $unitRows = $byUnit[$unitKey];
        $label = $unitRows[0]['unit_label'];
        $qnt = array_sum(array_column($unitRows, 'qnt'));
        $total = array_sum(array_column($unitRows, 'total'));
        $pricePerUnit = $qnt > 0 ? $total / $qnt : null;
        $current = $qnt > 0 ? array_sum(array_column($unitRows, 'qnt_base')) / $qnt : null;
        $rawFactor = $qnt > 0 ? array_sum(array_column($unitRows, 'raw_qnt_base')) / $qnt : null;
        $nifs = [];
        foreach ($unitRows as $r) {
            if ($r['nif'] !== '') {
                $nifs[$r['nif']] = ['nif' => $r['nif'], 'name' => $r['supplier']];
            }
        }
        $conf = null;
        foreach ($this->conversions[$cid] ?? [] as $key => $c) {
            if (str_starts_with($key, $unitKey . '|')) {
                $conf = $c;
                break;
            }
        }

        // A família da unidade base do componente (do artigo; senão a da linha da ficha).
        $item = $this->item($cid);
        $baseId = (string) ($item?->base_unit_id ?: $l['unit_id']);
        $base = $this->units[$this->unitRoot($baseId) ?? $baseId] ?? null;
        $family = $base ? RecipeHints::family($base->description, $base->shortname) : RecipeHints::family($l['unit']);
        $baseLabel = $base?->description ?? $l['unit'];

        $suggestions = [];
        $add = function (float $f, string $reason) use (&$suggestions, $current) {
            $f = RecipeHints::nice($f);
            if ($f <= 0 || ($current !== null && abs($f - $current) < 1e-6)) {
                return;
            }
            foreach ($suggestions as &$s) {
                if (abs($s['factor'] - $f) < 1e-6) {
                    $s['reasons'][] = $reason;

                    return;
                }
            }
            $suggestions[] = ['factor' => $f, 'reasons' => [$reason]];
        };
        if ($family) {
            $texts = array_unique(array_filter(array_merge([$item?->description, $l['description']], array_column($unitRows, 'description'))));
            foreach ($texts as $t) {
                foreach (RecipeHints::quantitiesInText($t, $family) as $q) {
                    $add($q['quantity'], "Descrição: «{$q['text']}» em «{$t}»");
                }
            }
        }
        // A razão de preços só quando a compra não tem fator (1): se já tem (ex.: Saco 15kg), a
        // diferença para o PingWin é de preço, não de unidade.
        $pwUnit = (float) ($pw['unit_cost'] ?? 0);
        if ($pricePerUnit !== null && $pwUnit > 0 && $pricePerUnit / $pwUnit >= 1.5 && $rawFactor !== null && abs($rawFactor - 1) < 1e-6) {
            $add($pricePerUnit / $pwUnit, sprintf('Preço por «%s» ÷ custo PingWin por %s = %s',
                $label, mb_strtolower((string) $baseLabel), number_format($pricePerUnit / $pwUnit, 2, ',', ' ')));
        }
        $u = $this->units[$unitKey] ?? null;
        if ($u && (float) $u->unit_value > 1) {
            $add((float) $u->unit_value, "Valor da unidade «{$u->description}» no PingWin");
        }

        $effect = function (float $factor) use ($cid, $unitKey, $l, $pw) {
            $rows = array_map(function ($r) use ($unitKey, $factor) {
                if ($r['unit_key'] === $unitKey) {
                    $r['qnt_base'] = $r['qnt'] * $factor;
                }

                return $r;
            }, $this->purchaseRows[$cid] ?? []);
            $st = self::purchaseStats($rows, $this->today);
            $line = $st['avg'] !== null ? round($l['qnt_base'] * $st['avg'] * (1 + $l['waste'] / 100), 6) : null;
            $diff = ($line !== null && ($pw['cost'] ?? 0) > 0) ? round(($line - $pw['cost']) / $pw['cost'] * 100, 1) + 0.0 : null;

            return ['unit_cost_eur' => $st['avg'], 'line_cost_eur' => $line, 'diff_pct' => $diff];
        };

        return [
            'unit_key' => $unitKey,
            'unit_label' => $label,
            'base_label' => $baseLabel,
            'price_per_unit_eur' => $pricePerUnit !== null ? round($pricePerUnit, 4) : null,
            'raw_factor' => $rawFactor !== null ? round($rawFactor, 6) : null,
            'current_factor' => $current !== null ? round($current, 6) : null,
            'current' => $current !== null ? $effect($current) : null,
            'suppliers' => array_values($nifs),
            'confirmed' => $conf ? [
                'factor' => $conf->factor_to_base, 'supplier_nif' => $conf->supplier_nif ?: null,
                'confirmed_by' => $conf->confirmer?->name, 'confirmed_at' => $conf->confirmed_at?->toIso8601String(),
            ] : null,
            'suggestions' => array_map(fn ($s) => ['factor' => $s['factor'], 'reason' => implode('; ', $s['reasons'])] + $effect($s['factor']), $suggestions),
        ];
    }

    /**
     * Um artigo parecido (pelo nome normalizado) comprado nos últimos 90 dias, para um componente
     * sem compras (ex.: a ficha usa o 100366 COCA-COLA ZERO GARRAFA; compra-se o 100415 COCA COLA
     * ZERO 0.35). Devolve o mais parecido, com o último preço, ou null.
     */
    public function similarPurchased(string $componentId, string $description): ?array
    {
        $tokens = RecipeHints::nameTokens($this->item($componentId)?->description ?? $description);
        if (count($tokens) < 1) {
            return null;
        }
        if ($this->purchased === null) {
            $this->purchased = [];
            $since = $this->today->subDays(self::WINDOW_DAYS)->toDateString();
            $rows = DB::table('pingwin_supplier_document_lines as l')
                ->join('pingwin_supplier_documents as d', fn ($j) => $j->on('d.docheader_id', '=', 'l.docheader_id')->on('d.company_id', '=', 'l.company_id'))
                ->where('l.company_id', $this->companyId)->whereNotNull('l.product_pingwin_id')
                ->whereIn('d.docconfig_id', self::PURCHASE_DOCCONFIGS)->where('d.docstatus_id', SupplierDocumentLinesService::STATUS_CLOSED)
                ->whereBetween('d.doc_date', [$since, $this->today->toDateString()])
                ->groupBy('l.product_pingwin_id')
                ->get(['l.product_pingwin_id', DB::raw('MAX(l.description) as description'), DB::raw('MAX(l.product_code) as code')]);
            foreach ($rows as $r) {
                $this->purchased[(string) $r->product_pingwin_id] = [
                    'description' => (string) $r->description, 'code' => $r->code,
                    'tokens' => RecipeHints::nameTokens((string) $r->description),
                ];
            }
        }
        $best = null;
        foreach ($this->purchased as $pid => $p) {
            if ((string) $pid === $componentId) {
                continue;
            }
            $score = RecipeHints::similarity($tokens, $p['tokens']);
            if ($score >= 0.75 && (! $best || $score > $best['score'])) {
                $best = ['product_id' => (string) $pid, 'code' => $p['code'] ? ltrim((string) $p['code'], "\u{FEFF}") : null,
                    'description' => $p['description'], 'score' => $score];
            }
        }
        if (! $best) {
            return null;
        }
        if (! isset($this->purchase[$best['product_id']])) {
            $saved = [$this->purchase, $this->purchaseRows];
            $this->loadPurchases([$best['product_id']]);
            $stats = $this->purchase[$best['product_id']];
            [$this->purchase, $this->purchaseRows] = $saved;
        } else {
            $stats = $this->purchase[$best['product_id']];
        }

        return $best + ['last_eur' => $stats['last'], 'avg_eur' => $stats['avg'], 'last_purchase' => $stats['last_date']];
    }

    public static function versionInfo(array $v): array
    {
        return [
            'bom_id' => $v['bom_id'], 'key' => $v['key'], 'valid_from' => $v['valid_from'], 'deleted' => $v['deleted'],
            'stores' => $v['stores'], 'line_count' => count($v['lines']),
        ];
    }

    private static function pct(float $ratio): string
    {
        return number_format($ratio * 100, 0, ',', ' ');
    }
}
