<?php

declare(strict_types=1);

namespace App\Services\Restaurant;

use App\Models\PingwinCatalogItem;
use App\Models\PingwinLocation;
use App\Models\PingwinProductBom;
use App\Models\PingwinProductBomLine;
use App\Models\PingwinWarehouse;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * XPLENDOR — Fatia 1: os ecrãs das fichas técnicas (só leitura), sobre o espelho e o custo
 * XPLENDOR (RecipeCostService).
 *  · lista: os artigos vendidos nos últimos 90 dias e os outros com ficha, por Pareto das vendas,
 *    com estado, custo, preço sem IVA (vendas ÷ quantidade), food cost e margem;
 *  · detalhe: as versões (a em vigor marcada), as lojas, as linhas com o custo XPLENDOR e o do
 *    PingWin, os semi-produtos, os totais, os avisos e onde o artigo é usado.
 * Os valores em euros vêm em campos *_eur; quem não vê as Finanças recebe-os a null (redact).
 */
final class RecipeReportService
{
    public const STATES = ['com_ficha', 'sem_ficha', 'sem_custo', 'loja_sem_ficha', 'rendimento_por_confirmar', 'fator_por_confirmar'];

    public const STATE_LABELS = [
        'com_ficha' => 'Com ficha',
        'sem_ficha' => 'Sem ficha',
        'sem_custo' => 'Com ficha mas sem custo',
        'loja_sem_ficha' => 'Loja sem ficha em vigor',
        // Fatia 1b: marcas que se juntam ao estado (um artigo pode ter as duas).
        'rendimento_por_confirmar' => 'Rendimento por confirmar',
        'fator_por_confirmar' => 'Fator por confirmar',
    ];

    /** Abreviatura da unidade base, para as frases ("cerca de 30 L"). */
    private const FAMILY_ABBR = ['L' => 'L', 'KG' => 'kg', 'UN' => 'unidades'];

    /** A parte das vendas que conta como "top" (Pareto). */
    public const TOP_SHARE = 80.0;

    private RecipeCostService $cost;

    /** @var array<int, PingwinLocation> */
    private array $locations;

    public function __construct(private int $companyId, ?CarbonInterface $today = null)
    {
        $this->cost = new RecipeCostService($companyId, $today);
        $this->locations = PingwinLocation::where('company_id', $companyId)->orderBy('id')->get()->keyBy('id')->all();
    }

    public function costService(): RecipeCostService
    {
        return $this->cost;
    }

    /** As lojas com vendas (para o filtro), com o id da loja no PingWin e o nome da base de dados. */
    public function locations(): array
    {
        $withSales = PingwinLocation::where('company_id', $this->companyId)->withSales()->pluck('id')->all();

        return array_values(array_map(fn ($l) => [
            'id' => $l->id, 'name' => $l->display_name ?: $l->winrest_name, 'store_id' => (string) $l->winrest_store_id,
        ], array_filter($this->locations, fn ($l) => in_array($l->id, $withSales, true))));
    }

    /**
     * A lista (todas as linhas, já com Pareto e filtros; a página ordena e corta).
     *
     * @param  array{family?:?string, location_id?:?int, state?:?string}  $filters
     * @return array{rows: list<array>, summary: array, families: list<string>}
     */
    public function list(array $filters = []): array
    {
        $today = $this->cost->today();
        $since = $today->subDays(RecipeCostService::WINDOW_DAYS)->toDateString();
        $location = isset($filters['location_id']) ? ($this->locations[(int) $filters['location_id']] ?? null) : null;
        $storeId = $location ? (string) $location->winrest_store_id : null;

        // Vendas dos últimos 90 dias, por artigo (e por loja, para "loja sem ficha em vigor").
        $sales = DB::table('pingwin_item_sales_daily')
            ->where('company_id', $this->companyId)
            ->whereBetween('business_date', [$since, $today->toDateString()])
            ->when($location, fn ($q) => $q->where('location_id', $location->id))
            ->groupBy('product_pingwin_id', 'location_id')
            ->get([
                'product_pingwin_id', 'location_id',
                DB::raw('SUM(quantity) as qty'), DB::raw('SUM(net_cents) as net'),
                DB::raw('MAX(product_name) as name'), DB::raw('MAX(product_code) as code'), DB::raw('MAX(family_path) as family'),
            ]);
        $byProduct = [];
        foreach ($sales as $s) {
            $pid = (string) $s->product_pingwin_id;
            $byProduct[$pid] ??= ['qty' => 0.0, 'net' => 0, 'locations' => [], 'name' => $s->name, 'code' => $s->code, 'family' => $s->family];
            $byProduct[$pid]['qty'] += (float) $s->qty;
            $byProduct[$pid]['net'] += (int) $s->net;
            if ((float) $s->qty > 0) {
                $byProduct[$pid]['locations'][] = (int) $s->location_id;
            }
        }

        $withBom = PingwinProductBom::where('company_id', $this->companyId)->distinct()->pluck('product_pingwin_id')->map(fn ($v) => (string) $v)->all();
        // (as chaves numéricas dos arrays voltam como int: tudo em string)
        $ids = array_values(array_unique(array_map('strval', array_merge(array_keys($byProduct), $withBom))));
        $this->cost->load($withBom);
        $items = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (PingwinCatalogItem::where('company_id', $this->companyId)->whereIn('pingwin_id', $chunk)->get() as $item) {
                $items[(string) $item->pingwin_id] = $item;
            }
        }

        $rows = [];
        foreach ($ids as $pid) {
            $rows[] = $this->row($pid, $items[$pid] ?? null, $byProduct[$pid] ?? null, $storeId, $location);
        }

        // Pareto: vendas por ordem decrescente; % e % acumulada sobre o total (antes dos filtros).
        usort($rows, fn ($a, $b) => [$b['_net'], $a['description']] <=> [$a['_net'], $b['description']]);
        $total = array_sum(array_column($rows, '_net'));
        $cum = 0;
        $coveredNet = 0;
        $topWithout = 0;
        foreach ($rows as $i => &$r) {
            $before = $total > 0 ? $cum / $total * 100 : 100;
            $cum += $r['_net'];
            $r['pareto'] = $i + 1;
            $r['share_pct'] = $total > 0 ? round($r['_net'] / $total * 100, 2) : null;
            $r['cum_pct'] = $total > 0 ? round($cum / $total * 100, 2) : null;
            if ($r['state'] !== 'sem_ficha') {
                $coveredNet += $r['_net'];
            } elseif ($r['_net'] > 0 && $before < self::TOP_SHARE) {
                $topWithout++;
            }
        }
        unset($r);

        $families = array_values(array_unique(array_filter(array_column($rows, 'family'))));
        sort($families);

        $rows = array_values(array_filter($rows, function ($r) use ($filters) {
            if (! empty($filters['family']) && $r['family'] !== $filters['family']) {
                return false;
            }

            return empty($filters['state']) || $r['state'] === $filters['state'] || in_array($filters['state'], $r['flags'], true);
        }));

        return [
            'rows' => $rows,
            'summary' => [
                'coverage_pct' => $total > 0 ? round($coveredNet / $total * 100, 1) : null,
                'top_without_bom' => $topWithout,
                'top_share' => self::TOP_SHARE,
                'articles' => count($ids),
                'with_bom' => count($withBom),
                'window_days' => RecipeCostService::WINDOW_DAYS,
                'since' => $since,
                'until' => $today->toDateString(),
                'last_synced_at' => PingwinProductBom::where('company_id', $this->companyId)->max('synced_at'),
            ],
            'families' => $families,
        ];
    }

    private function row(string $pid, ?PingwinCatalogItem $item, ?array $sales, ?string $storeId, ?PingwinLocation $location): array
    {
        $versions = $this->cost->versionsOf($pid);
        $date = $this->cost->today()->toDateString();
        $version = RecipeCostService::versionInForce($versions, $date, $storeId);
        $costed = $version ? $this->cost->costVersion($version, $pid) : null;

        // Lojas que venderam o artigo e não têm versão em vigor (com o filtro de loja: só essa).
        $missing = [];
        if ($versions) {
            $locIds = $location ? [$location->id] : array_unique($sales['locations'] ?? []);
            foreach ($locIds as $locId) {
                $loc = $this->locations[$locId] ?? null;
                if ($loc && ! RecipeCostService::versionInForce($versions, $date, (string) $loc->winrest_store_id)) {
                    $missing[] = $loc->display_name ?: $loc->winrest_name;
                }
            }
        }

        $state = ! $versions ? 'sem_ficha' : ($missing || ! $version ? 'loja_sem_ficha' : ($costed && $costed['complete'] ? 'com_ficha' : 'sem_custo'));
        // Marcas da Fatia 1b: rendimento por confirmar (o semi-produto, ou um prato que usa um
        // semi-produto sem rendimento) e fator por confirmar (um componente com compras).
        $yield = $version && $this->cost->isSemi($pid) ? $this->cost->yieldInfo($pid, $version) : null;
        $flags = [];
        if (($yield && ! $yield['confirmed']) || ($costed['low_confidence'] ?? false)) {
            $flags[] = 'rendimento_por_confirmar';
        }
        if ($costed['needs_factor'] ?? false) {
            $flags[] = 'fator_por_confirmar';
        }
        $sameDay = $version ? $this->sameDayWarning($versions, $version) : null;
        $qty = (float) ($sales['qty'] ?? 0);
        $net = (int) ($sales['net'] ?? 0);
        $price = $qty > 0 ? $net / 100 / $qty : null;
        $cost = $costed ? $costed['cost'] : null;

        return [
            'product_id' => $pid,
            'catalog_item_id' => $item?->id,
            'code' => $item?->code ?? $sales['code'] ?? null,
            'description' => $item?->description ?? $sales['name'] ?? $pid,
            'family' => self::family($item?->family ?? $sales['family'] ?? null),
            'qty' => round($qty, 3),
            'sales_eur' => round($net / 100, 2),
            '_net' => $net,
            'state' => $state,
            'state_label' => self::STATE_LABELS[$state],
            'flags' => $flags,
            'flag_labels' => array_map(fn ($f) => self::STATE_LABELS[$f], $flags),
            'low_confidence' => (bool) ($costed['low_confidence'] ?? false),
            'yield' => $yield ? ['quantity' => $yield['quantity'], 'unit_label' => $yield['unit_label'], 'confirmed' => $yield['confirmed']] : null,
            'cost_per_unit_eur' => $yield && $yield['confirmed'] && $cost !== null && $yield['quantity'] > 0 ? round($cost / $yield['quantity'], 4) : null,
            'missing_stores' => $missing,
            'components' => $version ? count($version['lines']) : null,
            'cost_eur' => $cost !== null ? round($cost, 4) : null,
            'cost_complete' => $costed['complete'] ?? null,
            'pingwin_cost_eur' => $costed ? round($costed['pingwin_cost'], 4) : null,
            'price_eur' => $price !== null ? round($price, 4) : null,
            'list_price_eur' => $item && $item->saleprice_cents !== null ? $item->saleprice_cents / 100 : null,
            'food_cost_pct' => ($cost !== null && $price) ? round($cost / $price * 100, 1) : null,
            'margin_eur' => ($cost !== null && $price !== null) ? round($price - $cost, 4) : null,
            'valid_from' => $version['valid_from'] ?? null,
            'stores' => $version ? $this->storeNames($version['stores']) : [],
            'warnings' => ($costed ? count($costed['warnings']) : 0) + count($missing) + ($sameDay ? 1 : 0) + ($yield && $yield['looks_like_batch'] ? 1 : 0),
        ];
    }

    /** Duas (ou mais) versões não anuladas com a mesma data de início: diz qual está em vigor. */
    private function sameDayWarning(array $versions, array $version): ?array
    {
        $same = array_values(array_filter($versions, fn ($v) => ! $v['deleted'] && $v['valid_from'] === $version['valid_from']));
        if (count($same) < 2) {
            return null;
        }
        usort($same, fn ($a, $b) => strcmp($a['key'], $b['key']));
        $n = array_search($version['bom_id'], array_column($same, 'bom_id'), true);
        $date = \Carbon\Carbon::parse($version['valid_from'])->format('d/m/Y');

        return ['type' => 'same_day', 'text' => sprintf('Há %d versões com início em %s: pela nossa regra (a criada depois) está em vigor a versão %d%s.',
            count($same), $date, (int) $version['key'] + 1, $version['lines'] ? '' : ', que não tem linhas')];
    }

    /** O aviso de ficha de lote (semi-produto sem rendimento confirmado). */
    private function batchWarning(array $yield, string $productId): ?array
    {
        if (! $yield['looks_like_batch']) {
            return null;
        }
        $family = null;
        foreach (['L', 'KG', 'UN'] as $f) {
            if (RecipeHints::family($yield['unit_label']) === $f) {
                $family = $f;
            }
        }
        $abbr = self::FAMILY_ABBR[$family] ?? mb_strtolower((string) $yield['unit_label']);
        $about = $yield['quantity'] ? sprintf(' (cerca de %s %s)', self::num($yield['quantity']), $abbr) : '';
        $sums = implode(' e ', array_map(fn ($x) => self::num($x['quantity']) . ' ' . $x['label'], $yield['sums']));
        // A1: nos pratos, o PingWin não usa a ficha do semi-produto; usa o custo de stock dele.
        $stock = PingwinProductBomLine::query()->join('pingwin_product_bom_line_costs as c', 'c.bom_line_id', '=', 'pingwin_product_bom_lines.id')
            ->where('pingwin_product_bom_lines.company_id', $this->companyId)->where('pingwin_product_bom_lines.component_pingwin_id', $productId)
            ->where('c.warehouse_pingwin_id', '')->max('c.unit_cost');
        $pingwin = $stock !== null && (float) $stock === 0.0
            ? ' Nos pratos, o PingWin não usa esta ficha: usa o custo de stock do semi-produto, que está a zero.'
            : '';

        return ['type' => 'batch', 'text' => "Esta ficha parece ser de um lote{$about}: os componentes somam {$sums}. "
            . "O PingWin conta a ficha por 1 {$yield['unit_label']}.{$pingwin} Confirme o rendimento."];
    }

    private static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, ',', ' '), '0'), ',');
    }

    /**
     * O detalhe de um artigo: versões, linhas com custos, totais, avisos e "Usado em".
     */
    public function detail(string $productId): ?array
    {
        $this->cost->load([$productId]);
        $item = PingwinCatalogItem::where('company_id', $this->companyId)->where('pingwin_id', $productId)->first();
        $versions = $this->cost->versionsOf($productId);
        if (! $item && ! $versions) {
            return null;
        }
        $today = $this->cost->today();
        $date = $today->toDateString();
        $since = $today->subDays(RecipeCostService::WINDOW_DAYS)->toDateString();

        $sales = DB::table('pingwin_item_sales_daily')->where('company_id', $this->companyId)
            ->where('product_pingwin_id', $productId)->whereBetween('business_date', [$since, $date])
            ->groupBy('location_id')->get(['location_id', DB::raw('SUM(quantity) as qty'), DB::raw('SUM(net_cents) as net')]);
        $qty = (float) $sales->sum('qty');
        $net = (int) $sales->sum('net');
        $price = $qty > 0 ? $net / 100 / $qty : null;

        $inForce = RecipeCostService::versionInForce($versions, $date);

        $isSemi = $this->cost->isSemi($productId) || PingwinProductBomLine::where('company_id', $this->companyId)
            ->where('component_pingwin_id', $productId)->where('is_bom', true)->exists();
        $out = [];
        foreach ($versions as $v) {
            $costed = $this->cost->costVersion($v, $productId);
            $costed['lines'] = array_map(fn ($l) => $this->shapeLine($l), $costed['lines']);
            $yield = $isSemi ? $this->cost->yieldInfo($productId, $v) : null;
            $perUnit = $yield && $costed['cost'] !== null ? $costed['cost'] / ($yield['confirmed'] && $yield['quantity'] > 0 ? $yield['quantity'] : 1) : null;
            $out[] = array_merge(RecipeCostService::versionInfo($v), [
                'in_force' => $inForce && $inForce['bom_id'] === $v['bom_id'],
                'stores' => $this->storesOf($v, $date, $versions),
                'lines' => $costed['lines'],
                'cost_eur' => $costed['cost'] !== null ? round($costed['cost'], 4) : null,
                'cost_complete' => $costed['complete'],
                'pingwin_cost_eur' => round($costed['pingwin_cost'], 4),
                'diff_pct' => $costed['diff_pct'],
                'food_cost_pct' => $price && $costed['cost'] !== null ? round($costed['cost'] / $price * 100, 1) : null,
                'margin_eur' => $price !== null && $costed['cost'] !== null ? round($price - $costed['cost'], 4) : null,
                'yield' => $yield,
                'cost_per_unit_eur' => $perUnit !== null ? round($perUnit, 4) : null,
                'low_confidence' => $costed['low_confidence'],
                'needs_factor' => $costed['needs_factor'],
                'warnings' => $this->versionWarnings($versions, $v, $yield, $costed, $productId),
            ]);
        }
        usort($out, fn ($a, $b) => [$b['valid_from'], $b['key']] <=> [$a['valid_from'], $a['key']]);

        // Lojas que venderam (90 dias) sem versão em vigor.
        $missing = $this->missingStores($sales, $versions, $date);

        return [
            'article' => [
                'product_id' => $productId,
                'catalog_item_id' => $item?->id,
                'code' => $item?->code,
                'description' => $item?->description,
                'family' => self::family($item?->family),
                'is_active' => $item?->is_active,
                'forsale' => $item?->forsale,
                'forproduction' => $item?->forproduction,
                'is_semi' => $isSemi,
                'qty_90d' => round($qty, 3),
                'sales_eur' => round($net / 100, 2),
                'price_eur' => $price !== null ? round($price, 4) : null,
                'list_price_eur' => $item && $item->saleprice_cents !== null ? $item->saleprice_cents / 100 : null,
                'list_price_wo_tax_eur' => $item && $item->saleprice_wo_tax_cents !== null ? $item->saleprice_wo_tax_cents / 100 : null,
                'bom_synced_at' => $item?->bom_synced_at,
            ],
            'state' => ! $versions ? 'sem_ficha' : ($missing || ! $inForce ? 'loja_sem_ficha'
                : (collect($out)->firstWhere('in_force', true)['cost_complete'] ?? false ? 'com_ficha' : 'sem_custo')),
            'flags' => array_values(array_filter([
                ($f = collect($out)->firstWhere('in_force', true)) && (($f['yield'] && ! $f['yield']['confirmed']) || $f['low_confidence']) ? 'rendimento_por_confirmar' : null,
                $f && $f['needs_factor'] ? 'fator_por_confirmar' : null,
            ])),
            'missing_stores' => $missing,
            'versions' => $out,
            'used_in' => $this->usedIn($productId, $date),
            'warehouses' => PingwinWarehouse::where('company_id', $this->companyId)->orderBy('description')->get(['pingwin_id', 'description'])
                ->map(fn ($w) => ['id' => (string) $w->pingwin_id, 'name' => $w->description])->all(),
            'window' => ['days' => RecipeCostService::WINDOW_DAYS, 'since' => $since, 'until' => $date],
        ];
    }

    /** Os avisos de uma versão: mesma data, rendimento por confirmar, lote e os das linhas. */
    private function versionWarnings(array $versions, array $v, ?array $yield, array $costed, string $productId): array
    {
        return array_values(array_filter(array_merge(
            [$this->sameDayWarning($versions, $v)],
            [$yield && ! $yield['confirmed'] ? ['type' => 'yield_unconfirmed', 'text' => 'Rendimento por confirmar: o custo por unidade usa 1 ' . $yield['unit_label'] . '.'] : null],
            [$yield ? $this->batchWarning($yield, $productId) : null],
            $costed['warnings'],
        )));
    }

    /**
     * As lojas que venderam o artigo (90 dias) sem versão da ficha em vigor.
     *
     * @param  iterable<object{location_id:int|string, qty:float|string}>  $sales
     */
    private function missingStores(iterable $sales, array $versions, string $date): array
    {
        $missing = [];
        foreach ($sales as $s) {
            $loc = $this->locations[(int) $s->location_id] ?? null;
            if ($versions && $loc && (float) $s->qty > 0 && ! RecipeCostService::versionInForce($versions, $date, (string) $loc->winrest_store_id)) {
                $missing[] = $loc->display_name ?: $loc->winrest_name;
            }
        }

        return $missing;
    }

    /**
     * Auditoria (os avisos das fichas como regras): para todos os artigos com ficha, as versões em
     * vigor ou futuras (não anuladas) com os MESMOS avisos do detalhe, e as lojas que venderam sem
     * ficha em vigor. Um só carregamento do espelho.
     *
     * @return array<string, array{code:?string, description:?string, sales_cents:int, missing_stores:list<string>, versions:list<array>}>
     */
    public function auditWarnings(): array
    {
        $this->cost->load(null);
        $today = $this->cost->today();
        $date = $today->toDateString();
        $since = $today->subDays(RecipeCostService::WINDOW_DAYS)->toDateString();
        $products = PingwinProductBom::where('company_id', $this->companyId)->distinct()->pluck('product_pingwin_id')->map(fn ($v) => (string) $v)->all();
        $semis = array_fill_keys(PingwinProductBomLine::where('company_id', $this->companyId)->where('is_bom', true)
            ->distinct()->pluck('component_pingwin_id')->map(fn ($v) => (string) $v)->all(), true);
        $sales = [];
        foreach (array_chunk($products, 500) as $chunk) {
            foreach (DB::table('pingwin_item_sales_daily')->where('company_id', $this->companyId)->whereIn('product_pingwin_id', $chunk)
                ->whereBetween('business_date', [$since, $date])->groupBy('product_pingwin_id', 'location_id')
                ->get(['product_pingwin_id', 'location_id', DB::raw('SUM(quantity) as qty'), DB::raw('SUM(net_cents) as net')]) as $r) {
                $sales[(string) $r->product_pingwin_id][] = $r;
            }
        }

        $out = [];
        foreach ($products as $pid) {
            $versions = $this->cost->versionsOf($pid);
            if (! $versions) {
                continue;
            }
            $inForce = RecipeCostService::versionInForce($versions, $date);
            $isSemi = $this->cost->isSemi($pid) || isset($semis[$pid]);
            $item = $this->cost->item($pid);
            $rows = [];
            foreach ($versions as $v) {
                $isInForce = $inForce && $inForce['bom_id'] === $v['bom_id'];
                if ($v['deleted'] || (! $isInForce && (string) $v['valid_from'] < $date)) {
                    continue; // versões antigas, já substituídas, não pedem atenção
                }
                $costed = $this->cost->costVersion($v, $pid);
                $yield = $isSemi ? $this->cost->yieldInfo($pid, $v) : null;
                $rows[] = RecipeCostService::versionInfo($v) + [
                    'in_force' => $isInForce,
                    'warnings' => $this->versionWarnings($versions, $v, $yield, $costed, $pid),
                    'yield' => $yield,
                ];
            }
            $out[$pid] = [
                'code' => $item?->code,
                'description' => $item?->description,
                'sales_cents' => (int) array_sum(array_map(fn ($r) => (int) $r->net, $sales[$pid] ?? [])),
                'missing_stores' => $this->missingStores($sales[$pid] ?? [], $versions, $date),
                'versions' => $rows,
            ];
        }

        return $out;
    }

    public function shapeLine(array $l): array
    {
        $sub = $l['sub'] ?? null;

        return [
            'key' => $l['key'], 'component_id' => $l['component_id'], 'code' => $l['code'], 'description' => $l['description'],
            'qnt' => $l['qnt'], 'unit' => $l['unit'], 'unit_id' => $l['unit_id'] ?? null, 'qnt_base' => $l['qnt_base'],
            'waste_pct' => $l['waste'], 'recovery_pct' => $l['recovery'], 'is_bom' => $l['is_bom'],
            'inactive' => $l['status'] === 2,
            'cost_source' => $l['cost_source'], 'cost_status' => $l['cost_status'],
            'unit_cost_last_eur' => $l['unit_cost_last'], 'unit_cost_avg_eur' => $l['unit_cost_avg'],
            'last_purchase' => $l['last_purchase'],
            'pingwin_unit_cost_eur' => $l['pingwin_unit_cost'], 'pingwin_cost_eur' => $l['pingwin_cost'],
            'line_cost_eur' => $l['line_cost'],
            'diff_pct' => $l['diff_pct'],
            'pingwin_by_warehouse' => array_map(fn ($w) => ['warehouse_id' => $w['warehouse_id'], 'unit_cost_eur' => $w['unit_cost'], 'cost_eur' => $w['cost']], $l['pingwin_by_warehouse']),
            'qty_source' => $l['qty_source'],
            'low_confidence' => $l['low_confidence'],
            'needs_factor' => $l['needs_factor'],
            'conversion' => $l['conversion'],
            'similar' => $l['similar'],
            'warnings' => $l['warnings'],
            'sub' => $sub ? [
                'product_id' => $sub['product_id'],
                'version' => $sub['version'],
                'cost_eur' => $sub['cost'] !== null ? round($sub['cost'], 4) : null,
                'cost_per_unit_eur' => $sub['cost_per_unit'] !== null ? round($sub['cost_per_unit'], 6) : null,
                'yield' => $sub['yield'],
                'cost_complete' => $sub['complete'],
                'pingwin_cost_eur' => round($sub['pingwin_cost'], 4),
                'lines' => array_map(fn ($x) => $this->shapeLine($x), $sub['lines']),
            ] : null,
        ];
    }

    /** As fichas (versões não anuladas) que usam este artigo como componente. */
    private function usedIn(string $productId, string $date): array
    {
        $lines = PingwinProductBomLine::where('company_id', $this->companyId)->where('component_pingwin_id', $productId)
            ->get(['bom_pingwin_id', 'qnt', 'unit_desc']);
        if ($lines->isEmpty()) {
            return [];
        }
        $boms = PingwinProductBom::where('company_id', $this->companyId)->whereIn('bom_pingwin_id', $lines->pluck('bom_pingwin_id'))
            ->where('deleted', false)->get()->keyBy('bom_pingwin_id');
        $parents = $boms->pluck('product_pingwin_id')->unique()->values()->all();
        $costs = (new RecipeCostService($this->companyId, $this->cost->today()))->load($parents);
        $items = PingwinCatalogItem::where('company_id', $this->companyId)->whereIn('pingwin_id', $parents)->get()->keyBy(fn ($i) => (string) $i->pingwin_id);

        $out = [];
        foreach ($lines as $l) {
            $b = $boms[$l->bom_pingwin_id] ?? null;
            if (! $b) {
                continue;
            }
            $pid = (string) $b->product_pingwin_id;
            $inForce = RecipeCostService::versionInForce($costs->versionsOf($pid), $date);
            $out[] = [
                'product_id' => $pid,
                'code' => $items[$pid]->code ?? null,
                'description' => $items[$pid]->description ?? $pid,
                'valid_from' => $b->valid_from?->toDateString(),
                'in_force' => $inForce && $inForce['bom_id'] === (string) $b->bom_pingwin_id,
                'qnt' => (float) $l->qnt,
                'unit' => $l->unit_desc,
            ];
        }
        usort($out, fn ($a, $b) => [$b['in_force'], $a['description']] <=> [$a['in_force'], $b['description']]);

        return $out;
    }

    /** As lojas de uma versão, com o nome XPLENDOR e se é a versão em vigor nessa loja. */
    private function storesOf(array $v, string $date, array $versions): array
    {
        $byStore = [];
        foreach ($this->locations as $loc) {
            $byStore[(string) $loc->winrest_store_id] = $loc->display_name ?: $loc->winrest_name;
        }

        return array_map(function ($s) use ($byStore, $date, $versions, $v) {
            $force = RecipeCostService::versionInForce($versions, $date, $s['store_id']);

            return [
                'store_id' => $s['store_id'], 'code' => $s['code'],
                'name' => $byStore[$s['store_id']] ?? $s['name'],
                'active' => $s['active'],
                'in_force' => $force !== null && $force['bom_id'] === $v['bom_id'],
            ];
        }, $v['stores']);
    }

    /** @return list<string> os nomes das lojas ativas da versão */
    private function storeNames(array $stores): array
    {
        $byStore = [];
        foreach ($this->locations as $loc) {
            $byStore[(string) $loc->winrest_store_id] = $loc->display_name ?: $loc->winrest_name;
        }

        return array_values(array_map(fn ($s) => $byStore[$s['store_id']] ?? $s['name'], array_filter($stores, fn ($s) => $s['active'])));
    }

    /** "Família \ Bebidas \ Cerveja" → "Bebidas › Cerveja". */
    public static function family(?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        $parts = array_values(array_filter(array_map('trim', explode('\\', $path)), fn ($p) => $p !== ''));
        if ($parts && mb_strtolower($parts[0]) === 'família') {
            array_shift($parts);
        }

        return $parts ? implode(' › ', $parts) : null;
    }

    /** Sem Finanças: os campos em euros (*_eur) vão a null. */
    public static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::redact($value);
            } elseif (is_string($key) && str_ends_with($key, '_eur')) {
                $data[$key] = null;
            }
        }

        return $data;
    }
}
