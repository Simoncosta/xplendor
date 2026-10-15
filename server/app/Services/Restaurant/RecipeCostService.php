<?php

declare(strict_types=1);

namespace App\Services\Restaurant;

use App\Models\PingwinCatalogItem;
use App\Models\PingwinProductBom;
use App\Models\PingwinProductBomLine;
use App\Models\PingwinProductBomLineCost;
use App\Models\PingwinProductBomStore;
use App\Models\PingwinUnit;
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
 *    proteção contra ciclos. PRESSUPOSTO: a ficha de um semi-produto rende 1 unidade base dele.
 *  · Versão em vigor na data D e na loja L: a de valid_from mais recente ≤ D, não anulada, com L
 *    ativa (sem loja: com alguma loja ativa). Empate na data: a criada depois (key maior).
 */
final class RecipeCostService
{
    public const WINDOW_DAYS = 90;
    public const OUTLIER_FACTOR = 4.0;
    public const PINGWIN_DIFF = 0.20;
    public const MAX_DEPTH = 5;

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

    public function __construct(private int $companyId, ?CarbonInterface $today = null)
    {
        $this->today = CarbonImmutable::parse(($today ?? now('Europe/Lisbon'))->toDateString());
    }

    public function today(): CarbonImmutable
    {
        return $this->today;
    }

    /** Carrega o espelho das fichas (todas ou só destes artigos) e as compras dos componentes. */
    public function load(?array $productIds = null): self
    {
        $boms = PingwinProductBom::where('company_id', $this->companyId)
            ->when($productIds !== null, fn ($q) => $q->whereIn('product_pingwin_id', $productIds))
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

        $components = [];
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
        $this->loadPurchases(array_keys($components));

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
        $rows = [];
        foreach (array_chunk($componentIds, 500) as $chunk) {
            $q = DB::table('pingwin_supplier_document_lines as l')
                ->join('pingwin_supplier_documents as d', fn ($j) => $j->on('d.docheader_id', '=', 'l.docheader_id')->on('d.company_id', '=', 'l.company_id'))
                ->where('l.company_id', $this->companyId)
                ->whereIn('l.product_pingwin_id', $chunk)
                ->whereIn('d.docconfig_id', self::PURCHASE_DOCCONFIGS)
                ->where('d.docstatus_id', SupplierDocumentLinesService::STATUS_CLOSED)
                ->where('d.doc_date', '<=', $this->today->toDateString())
                ->orderBy('d.doc_date')->orderBy('l.id')
                ->get(['l.id', 'l.product_pingwin_id', 'l.qnt', 'l.total_cents', 'l.unit_desc', 'l.raw', 'd.doc_date']);
            foreach ($q as $r) {
                $raw = is_string($r->raw) ? (json_decode($r->raw, true) ?: []) : (array) $r->raw;
                $qb = $raw['qnt_base'] ?? null;
                if (! is_numeric($qb)) {
                    $qb = (float) $r->qnt * (is_numeric($raw['unit_value'] ?? null) ? (float) $raw['unit_value'] : 1.0);
                }
                $date = substr((string) $r->doc_date, 0, 10);
                $pid = (string) $r->product_pingwin_id;
                $rows[$pid][] = ['id' => (int) $r->id, 'date' => $date, 'total' => ((int) $r->total_cents) / 100, 'qnt_base' => (float) $qb];
                if ($date >= $since && ! empty($raw['unit_id'])) {
                    $this->purchaseUnits[$pid][(string) $raw['unit_id']] = (string) $raw['unit_id'];
                }
            }
        }
        foreach ($componentIds as $id) {
            $this->purchase[$id] = self::purchaseStats($rows[$id] ?? [], $this->today);
        }
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

    private static function median(array $values): float
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

    /** @return list<array> as versões do artigo no espelho (vazio = sem ficha) */
    public function versionsOf(string $productId): array
    {
        return $this->versions[$productId] ?? [];
    }

    public function item(string $productId): ?PingwinCatalogItem
    {
        return $this->items[$productId] ?? null;
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

        foreach ($version['lines'] as $l) {
            $row = $this->costLine($l, $depth, $path);
            $lines[] = $row;
            $pingwin += (float) ($row['pingwin_cost'] ?? 0);
            if ($row['line_cost'] === null || $row['cost_status'] === 'parcial') {
                $complete = false;
            }
            if ($row['line_cost'] !== null) {
                $cost += $row['line_cost'];
                $anyCost = true;
            }
            foreach ($row['warnings'] as $w) {
                $warnings[] = $w + ['line' => $row['code'] ?? $row['description']];
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
            'diff_pct' => $diff !== null ? round($diff * 100, 1) : null,
            'warnings' => $warnings,
        ];
    }

    private function costLine(array $l, int $depth, array $path): array
    {
        $cid = $l['component_id'];
        $warnings = [];
        $sub = null;
        $source = 'compras';

        if ($l['is_bom'] && $this->versionsOf($cid)) {
            $source = 'ficha';
            if (in_array($cid, $path, true)) {
                $unit = ['status' => 'ciclo', 'last' => null, 'avg' => null, 'last_date' => null, 'lines' => 0, 'outliers' => 0];
                $warnings[] = ['type' => 'cycle', 'text' => 'Ciclo: o semi-produto usa-se a si próprio (direta ou indiretamente).'];
            } elseif ($depth + 1 > self::MAX_DEPTH) {
                $unit = ['status' => 'fundo', 'last' => null, 'avg' => null, 'last_date' => null, 'lines' => 0, 'outliers' => 0];
                $warnings[] = ['type' => 'depth', 'text' => 'Semi-produtos com mais de 5 níveis: o custo não foi calculado.'];
            } else {
                // Custo parcial do semi-produto (com linhas sem custo) conta, marcado como parcial.
                $sub = $this->semiProduct($cid, $depth + 1, $path);
                $value = $sub['cost'] ?? null;
                $status = $value === null ? 'sem_custo' : ($sub['complete'] ? 'ok' : 'parcial');
                $unit = ['status' => $status, 'last' => $value, 'avg' => $value, 'last_date' => null, 'lines' => 0, 'outliers' => 0];
            }
        } else {
            $unit = $this->purchaseOf($cid);
        }

        $lineCost = $unit['avg'] !== null ? round($l['qnt_base'] * $unit['avg'] * (1 + $l['waste'] / 100), 6) : null;
        $pw = $l['pingwin'][''] ?? ['unit_cost' => null, 'cost' => null];

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
        if ($u = $this->suspiciousUnit($cid, $l['unit_id'])) {
            $warnings[] = ['type' => 'unit_factor', 'text' => "Unidade «{$u}» com fator 1: o custo por unidade pode estar errado."];
        } elseif ($source === 'compras' && ($diff === null || abs($diff) > self::PINGWIN_DIFF)
            && ($u = $this->foreignPurchaseUnit($cid, $l['unit_id']))) {
            // Só quando o custo não bate com o do PingWin (se bater, a unidade da compra está certa).
            $warnings[] = ['type' => 'unit_factor', 'text' => "Compras em «{$u}» e ficha em «{$l['unit']}»: o custo por unidade pode estar errado."];
        }

        return [
            'key' => $l['key'], 'component_id' => $cid, 'code' => $l['code'], 'description' => $l['description'],
            'qnt' => $l['qnt'], 'unit' => $l['unit'], 'qnt_base' => $l['qnt_base'],
            'waste' => $l['waste'], 'recovery' => $l['recovery'], 'is_bom' => $l['is_bom'], 'status' => $status,
            'cost_source' => $source,
            'cost_status' => $unit['status'],
            'unit_cost_last' => $unit['last'], 'unit_cost_avg' => $unit['avg'], 'last_purchase' => $unit['last_date'],
            'pingwin_unit_cost' => $pw['unit_cost'] ?? null, 'pingwin_cost' => $pw['cost'] ?? null,
            'line_cost' => $lineCost,
            'diff_pct' => $diff !== null ? round($diff * 100, 1) : null,
            'pingwin_by_warehouse' => $l['pingwin'],
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

        return $this->memo[$memoKey] = $res + ['version' => self::versionInfo($v), 'product_id' => $productId];
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
    private function unitRoot(?string $unitId): ?string
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
