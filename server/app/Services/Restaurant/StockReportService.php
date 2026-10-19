<?php

declare(strict_types=1);

namespace App\Services\Restaurant;

use App\Audit\Rules\Restaurant\RestaurantAuditData;
use App\Models\PingwinCatalogItem;
use App\Models\PingwinStock;
use App\Models\PingwinStockItem;
use App\Models\PingwinStockRun;
use App\Models\PingwinStockSnapshot;
use App\Models\PingwinStockWrite;
use App\Models\PingwinWarehouse;
use App\Services\PingwinService;

/**
 * XPLENDOR — Fatia 7a: Restauração › Stock (só leitura do espelho). Uma linha por artigo, no armazém
 * escolhido ou em todos: quantidade, PCM (custo médio), PCU (último custo), valor (quantidade × PCM),
 * mínimo e máximo, consumo teórico por dia, dias de cobertura e avisos. Por ordem de Pareto do valor.
 * Os euros vão em campos *_eur, que o controller apaga a quem não vê as Finanças.
 */
final class StockReportService
{
    public const STATES = ['negativo', 'abaixo_minimo', 'sem_consumo', 'cobertura_60'];

    public const STATE_LABELS = [
        'negativo' => 'Stock negativo',
        'abaixo_minimo' => 'Abaixo do mínimo',
        'sem_consumo' => 'Com stock e sem consumo',
        'cobertura_60' => 'Cobertura acima de 60 dias',
    ];

    public const HIGH_COVERAGE_DAYS = 60;

    private StockConsumptionService $consumption;

    public function __construct(private int $companyId)
    {
        $this->consumption = new StockConsumptionService($companyId);
    }

    /** Os armazéns ativos (para o seletor), com as lojas que servem. */
    public function warehouses(): array
    {
        return PingwinWarehouse::where('company_id', $this->companyId)->where('deleted', false)->orderBy('code')->get()
            ->map(fn ($w) => ['id' => (string) $w->pingwin_id, 'code' => $w->code, 'name' => $w->description,
                'stores' => array_values(array_map(fn ($s) => $s['name'] ?? $s['code'], array_filter((array) $w->stores, fn ($s) => ! empty($s['served']))))])
            ->values()->all();
    }

    /**
     * A lista (todas as linhas, já filtradas e por Pareto), o resumo e as opções dos filtros.
     *
     * @param  array{warehouse_id?:?string, family?:?string, state?:?string}  $filters
     */
    public function list(array $filters = []): array
    {
        $warehouses = $this->warehouses();
        $activeIds = array_column($warehouses, 'id');
        $wid = $filters['warehouse_id'] ?? null;
        $ids = $wid ? [$wid] : $activeIds;

        $stock = PingwinStock::where('company_id', $this->companyId)->whereIn('warehouse_pingwin_id', $ids)->get()
            ->groupBy(fn ($s) => (string) $s->product_pingwin_id);
        $items = PingwinCatalogItem::where('company_id', $this->companyId)->whereIn('pingwin_id', $stock->keys()->all())->get()->keyBy(fn ($i) => (string) $i->pingwin_id);
        $units = PingwinStockItem::where('company_id', $this->companyId)->whereIn('product_pingwin_id', $stock->keys()->all())->pluck('stock_unit', 'product_pingwin_id');
        $avg = $this->consumption->averages();

        $rows = [];
        foreach ($stock as $pid => $lines) {
            $pid = (string) $pid;
            $item = $items[$pid] ?? null;
            $row = $this->row($pid, $item, $lines->all(), $avg['components'][$pid] ?? null, $wid, $units[$pid] ?? null);
            $rows[] = $row;
        }

        $families = array_values(array_unique(array_filter(array_map(fn ($r) => $r['family_group'], $rows))));
        sort($families, SORT_STRING | SORT_FLAG_CASE);

        // Pareto pelo valor (as linhas com valor positivo), antes dos filtros de família e estado.
        usort($rows, fn ($a, $b) => [$b['_value'], $a['description']] <=> [$a['_value'], $b['description']]);
        $positive = array_sum(array_map(fn ($r) => max(0.0, $r['_value']), $rows));
        $cum = 0.0;
        $top80 = 0;
        foreach ($rows as $i => &$r) {
            $r['pareto'] = $i + 1;
            $share = $positive > 0 && $r['_value'] > 0 ? $r['_value'] / $positive * 100 : 0.0;
            $cum += $share;
            $r['share_pct'] = round($share, 2);
            $r['cum_pct'] = $r['_value'] > 0 ? round(min(100.0, $cum), 2) : null;
            if ($r['_value'] > 0 && $cum - $share < 80) {
                $top80++;
            }
        }
        unset($r);

        $counts = array_fill_keys(self::STATES, 0);
        foreach ($rows as $r) {
            foreach ($r['states'] as $s) {
                $counts[$s]++;
            }
        }
        if ($f = $filters['family'] ?? null) {
            $rows = array_values(array_filter($rows, fn ($r) => $r['family_group'] === $f));
        }
        if ($s = $filters['state'] ?? null) {
            $rows = array_values(array_filter($rows, fn ($r) => in_array($s, $r['states'], true)));
        }

        $lastRun = PingwinStockRun::where('company_id', $this->companyId)->where('status', 'done')->latest('finished_at')->first();
        $lastRead = PingwinStock::where('company_id', $this->companyId)->max('synced_at');

        return [
            'rows' => $rows,
            'summary' => [
                'articles' => count($stock),
                'value_eur' => round($positive, 2),
                'top80' => $top80,
                'counts' => $counts,
                'window' => $avg['window'],
                'last_read_at' => $lastRead ? \Illuminate\Support\Carbon::parse($lastRead)->toIso8601String() : null,
                'last_run' => $lastRun ? ['finished_at' => $lastRun->finished_at?->toIso8601String(), 'origin' => $lastRun->origin,
                    'products_read' => $lastRun->products_read, 'products_failed' => $lastRun->products_failed] : null,
                'stores' => array_values($this->consumption->storeWarehouses()),
            ],
            'families' => $families,
            'warehouses' => $warehouses,
            'warehouse_id' => $wid,
        ];
    }

    /**
     * O stock de UM artigo por armazém, o consumo e quem o consome, a guarda das escritas, as últimas
     * escritas e a evolução (fotografias). Null se o artigo não existir.
     */
    public function article(string $productId): ?array
    {
        $item = PingwinCatalogItem::where('company_id', $this->companyId)->where('pingwin_id', $productId)->first();
        if (! $item) {
            return null;
        }
        $warehouses = collect($this->warehouses())->keyBy('id');
        $lines = PingwinStock::where('company_id', $this->companyId)->where('product_pingwin_id', $productId)
            ->whereIn('warehouse_pingwin_id', $warehouses->keys()->all())->get();
        $state = PingwinStockItem::where('company_id', $this->companyId)->where('product_pingwin_id', $productId)->first();
        $avg = $this->consumption->averages();
        $c = $avg['components'][$productId] ?? null;

        $byWarehouse = [];
        foreach ($lines->sortBy(fn ($l) => $warehouses[(string) $l->warehouse_pingwin_id]['code'] ?? '') as $l) {
            $w = $warehouses[(string) $l->warehouse_pingwin_id] ?? null;
            $perDay = $this->toStockUnit($c['by_warehouse'][(string) $l->warehouse_pingwin_id] ?? 0.0, $l->unit_value);
            $byWarehouse[] = [
                'warehouse_id' => (string) $l->warehouse_pingwin_id, 'warehouse' => $w['name'] ?? $l->warehouse_pingwin_id, 'code' => $w['code'] ?? null,
                'stores' => $w['stores'] ?? [],
                'unit' => $l->unit_name, 'qnt' => $l->qnt, 'pcm_eur' => $l->pcm, 'pcu_eur' => $l->pcu,
                'value_eur' => $l->pcm !== null ? round($l->qnt * $l->pcm, 2) : null,
                'min_stock' => $l->min_stock, 'max_stock' => $l->max_stock, 'disable_generic' => $l->disable_generic,
                'qnt_pend_cli' => $l->qnt_pend_cli, 'qnt_pend_sup' => $l->qnt_pend_sup,
                'valorization_date' => $l->valorization_date?->toDateString(),
                'per_day' => $perDay > 0 ? round($perDay, 4) : null,
                'coverage_days' => $l->qnt > 0 && $perDay > 0 ? round($l->qnt / $perDay, 1) : null,
                'states' => self::states($l->qnt, $l->min_stock, $perDay),
                'synced_at' => $l->synced_at?->toIso8601String(),
            ];
        }

        $sources = [];
        foreach (array_slice($c['sources'] ?? [], 0, 8, true) as $sid => $qty) {
            $sources[] = ['product_id' => (string) $sid, 'description' => PingwinCatalogItem::where('company_id', $this->companyId)->where('pingwin_id', (string) $sid)->value('description') ?? (string) $sid,
                'qty' => round((float) $qty, 3), 'via' => (string) $sid === $productId ? 'venda' : 'ficha'];
        }
        $usedBy = array_map(fn ($pid) => ['product_id' => $pid, 'description' => PingwinCatalogItem::where('company_id', $this->companyId)->where('pingwin_id', $pid)->value('description') ?? $pid],
            array_slice($this->consumption->usedInForceBy($productId), 0, 12));

        $history = PingwinStockSnapshot::where('company_id', $this->companyId)->where('product_pingwin_id', $productId)
            ->whereIn('warehouse_pingwin_id', $warehouses->keys()->all())->where('snapshot_date', '>=', now('Europe/Lisbon')->subDays(60)->toDateString())
            ->selectRaw('snapshot_date, SUM(qnt) as qnt')->groupBy('snapshot_date')->orderBy('snapshot_date')->get()
            ->map(fn ($s) => ['date' => substr((string) $s->snapshot_date, 0, 10), 'qnt' => round((float) $s->qnt, 3)])->values()->all();

        $writes = PingwinStockWrite::where('company_id', $this->companyId)->where('product_pingwin_id', $productId)->with('user:id,name')
            ->latest('id')->limit(5)->get()->map(fn ($w) => self::writeSummary($w))->all();

        $totalQnt = array_sum(array_column($byWarehouse, 'qnt'));
        $totalPerDay = $c ? $this->toStockUnit($c['per_day'], $lines->first()?->unit_value) : 0.0;

        return [
            'product' => ['id' => $productId, 'code' => $item->code, 'description' => $item->description,
                'family' => RestaurantAuditData::familyPath($item->family), 'forsale' => (bool) $item->forsale, 'forpurchase' => (bool) $item->forpurchase],
            'stock_item' => $state ? ['stockconfig' => $state->stockconfig, 'movestock' => $state->movestock, 'stock_unit' => $state->stock_unit,
                'last_read_at' => $state->last_read_at?->toIso8601String(), 'last_error' => $state->last_error] : null,
            'warehouses' => $byWarehouse,
            'total' => [
                'qnt' => round($totalQnt, 5),
                'value_eur' => $lines->every(fn ($l) => $l->pcm !== null) ? round($lines->sum(fn ($l) => $l->qnt * $l->pcm), 2) : null,
                'per_day' => $totalPerDay > 0 ? round($totalPerDay, 4) : null,
                'coverage_days' => $totalQnt > 0 && $totalPerDay > 0 ? round($totalQnt / $totalPerDay, 1) : null,
                'unassigned_per_day' => $c && $c['unassigned'] > 0 ? round($c['unassigned'], 4) : null,
            ],
            'consumption' => ['window' => $avg['window'], 'low_confidence' => (bool) ($c['low_confidence'] ?? false), 'sources' => $sources, 'used_by' => $usedBy],
            'history' => $history,
            'writes' => $writes,
            'write_guard' => PingwinService::stockWriteGuard($productId, $this->companyId),
        ];
    }

    /** O resumo de uma escrita, para o ecrã. */
    public static function writeSummary(PingwinStockWrite $w): array
    {
        return [
            'id' => $w->id, 'kind' => $w->kind, 'status' => $w->status, 'warehouse_id' => $w->warehouse_pingwin_id,
            'product_id' => $w->product_pingwin_id, 'payload' => $w->payload, 'error' => $w->error,
            'checks' => $w->result['checks'] ?? null, 'after' => $w->result['after'] ?? null, 'before' => $w->result['before'] ?? null,
            'user' => $w->user?->name, 'created_at' => $w->created_at?->toIso8601String(), 'finished_at' => $w->finished_at?->toIso8601String(),
        ];
    }

    /** @return list<string> os estados de uma linha */
    public static function states(float $qnt, ?float $min, float $perDay): array
    {
        $out = [];
        if ($qnt < 0) {
            $out[] = 'negativo';
        }
        if ($min !== null && $min > 0 && $qnt < $min) {
            $out[] = 'abaixo_minimo';
        }
        if ($qnt > 0 && $perDay <= 0) {
            $out[] = 'sem_consumo';
        }
        if ($qnt > 0 && $perDay > 0 && $qnt / $perDay > self::HIGH_COVERAGE_DAYS) {
            $out[] = 'cobertura_60';
        }

        return $out;
    }

    /** Consumo da unidade base para a unidade de stock (÷ unit_value; com 1 fica igual). */
    private function toStockUnit(float $perDayBase, ?float $unitValue): float
    {
        return $unitValue !== null && $unitValue > 0 ? $perDayBase / $unitValue : $perDayBase;
    }

    /** @param list<PingwinStock> $lines */
    private function row(string $pid, ?PingwinCatalogItem $item, array $lines, ?array $c, ?string $wid, ?string $stockUnit): array
    {
        $qnt = 0.0;
        $value = 0.0;
        $valued = true;
        $lastDate = null;
        $pcu = null;
        $states = [];
        $perDayTotal = 0.0;
        foreach ($lines as $l) {
            $qnt += $l->qnt;
            if ($l->pcm === null) {
                $valued = false;
            } else {
                $value += $l->qnt * $l->pcm;
            }
            $d = $l->valorization_date?->toDateString();
            if ($d !== null && ($lastDate === null || $d > $lastDate)) {
                $lastDate = $d;
                $pcu = $l->pcu;
            }
            $perDay = $this->toStockUnit($c['by_warehouse'][(string) $l->warehouse_pingwin_id] ?? 0.0, $l->unit_value);
            $perDayTotal += $perDay;
            $states = array_merge($states, array_intersect(self::states($l->qnt, $l->min_stock, $perDay), ['negativo', 'abaixo_minimo']));
        }
        if (! $wid && $c) {
            // Em "Todos", conta também o consumo das lojas sem armazém por omissão.
            $perDayTotal += $this->toStockUnit($c['unassigned'], $lines[0]->unit_value ?? null);
        }
        if ($pcu === null && $lines) {
            $pcu = $lines[0]->pcu;
        }
        $single = $wid && count($lines) === 1 ? $lines[0] : null;
        $states = array_values(array_unique(array_merge($states, array_intersect(self::states($qnt, null, $perDayTotal), ['sem_consumo', 'cobertura_60']))));

        return [
            'product_id' => $pid, 'code' => $item?->code, 'description' => $item?->description ?? $pid,
            'family' => RestaurantAuditData::familyPath($item?->family), 'family_group' => self::familyGroup($item?->family),
            'unit' => $stockUnit ?? ($lines[0]->unit_name ?? null),
            'qnt' => round($qnt, 5),
            'pcm_eur' => $valued && $qnt > 0 ? round($value / $qnt, 6) : ($single?->pcm ?? (count($lines) === 1 ? $lines[0]->pcm : null)),
            'pcu_eur' => $pcu,
            'value_eur' => $valued ? round($value, 2) : null,
            '_value' => $valued ? $value : 0.0,
            'min_stock' => $single?->min_stock, 'max_stock' => $single?->max_stock,
            'per_day' => $perDayTotal > 0 ? round($perDayTotal, 4) : null,
            'coverage_days' => $qnt > 0 && $perDayTotal > 0 ? round($qnt / $perDayTotal, 1) : null,
            'low_confidence' => (bool) ($c['low_confidence'] ?? false),
            'valorization_date' => $lastDate,
            'states' => $states,
            'warehouses' => count($lines),
        ];
    }

    /** O grupo de família para o filtro: os dois primeiros níveis ("COMPRAS › BEBIDAS"). */
    public static function familyGroup(?string $path): string
    {
        $parts = array_slice(explode(' › ', RestaurantAuditData::familyPath($path)), 0, 2);

        return implode(' › ', array_filter($parts));
    }
}
