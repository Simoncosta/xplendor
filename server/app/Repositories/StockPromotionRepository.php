<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Car;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Queries do Relatório A — candidatas a promoção.
 *
 * NÃO é um BaseRepository CRUD — é especializado em LEITURA agregada do
 * stock de uma empresa, com sub-selects para engagement (views/leads) e
 * eager loads para IPS + market aggregate + prioridade activa.
 *
 * Reaproveitamento do padrão `applyEngagementCounts` do `DashboardRepository`:
 * o método lá é PRIVATE. Em vez de mexer no Dashboard (que está validado e
 * produtivo), replico aqui o mesmo padrão de subqueries. Resultado SQL
 * equivalente, sem risco de regredir o Dashboard.
 */
class StockPromotionRepository
{
    /** Status visíveis no relatório — viaturas que ainda fazem sentido promover. */
    private const VISIBLE_STATUSES = ['active', 'available_soon', 'reserved'];

    /** Default da ordenação quando o caller não passa `sort_by`. */
    private const DEFAULT_SORT_BY = 'days_in_stock';
    private const DEFAULT_SORT_DIR = 'desc';

    /**
     * @param array{
     *   vehicle_type?: string|null,
     *   status?: string[]|null,
     *   min_price?: float|null,
     *   max_price?: float|null,
     *   min_days_in_stock?: int|null,
     *   max_days_in_stock?: int|null,
     *   price_signal?: string[]|null,
     *   only_marked?: bool,
     *   sort_by?: string|null,
     *   sort_dir?: string|null,
     * } $filters
     */
    public function queryCandidates(int $companyId, array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $query = $this->baseQuery($companyId);

        $this->applyFilters($query, $filters);
        $this->applyEngagementSubqueries($query, $companyId);
        $this->applySort($query, $filters);

        return $query->paginate($perPage);
    }

    /**
     * Resumo simples para o topo do relatório — totais agregados sobre o
     * stock visível, SEM filtros do utilizador. Custo: 2 queries leves.
     */
    public function summary(int $companyId): array
    {
        // Conta o total visível por vehicle_type, separadamente do que
        // já tem prioridade marcada. Duas queries pequenas em vez de uma
        // GROUP BY complexa — mais fácil de raciocinar.
        $totalsByType = DB::table('cars')
            ->where('company_id', $companyId)
            ->whereIn('status', self::VISIBLE_STATUSES)
            ->groupBy('vehicle_type')
            ->selectRaw('vehicle_type, COUNT(*) as total')
            ->pluck('total', 'vehicle_type')
            ->toArray();

        $markedTotal = DB::table('car_promotion_priorities')
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->count();

        return [
            'visible_by_type' => $totalsByType,
            'visible_total'   => array_sum($totalsByType),
            'marked_total'    => $markedTotal,
        ];
    }

    // ── Internals ─────────────────────────────────────────────────────────

    /**
     * Builder base com eager loads e tenant scope. O `promotionPriority`
     * relation no Car já filtra `is_active=true` (latestOfMany defensivo).
     */
    private function baseQuery(int $companyId): Builder
    {
        return Car::query()
            ->where('cars.company_id', $companyId)
            ->whereIn('cars.status', self::VISIBLE_STATUSES)
            ->with([
                'brand:id,name',
                'model:id,name',
                'category:id,name,slug',
                // Apenas a imagem principal — `images()` já está ordenada por
                // `is_primary DESC, order ASC, id ASC` desde T3 (2026-06-09).
                'images:id,car_id,image,is_primary,order',
                'latestMarketAggregate',
                'latestSalePotentialScore',
                'promotionPriority.markedBy:id,name',
            ]);
    }

    /**
     * Filtros explícitos do utilizador. `only_marked=true` faz EXISTS sobre
     * `car_promotion_priorities` para evitar puxar a relação só para filtrar.
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (!empty($filters['vehicle_type'])) {
            $query->where('cars.vehicle_type', $filters['vehicle_type']);
        }

        // Permite restringir status dentro do conjunto visível (default = todos).
        if (!empty($filters['status']) && is_array($filters['status'])) {
            $allowed = array_intersect($filters['status'], self::VISIBLE_STATUSES);
            if (!empty($allowed)) {
                $query->whereIn('cars.status', $allowed);
            }
        }

        if (isset($filters['min_price']) && is_numeric($filters['min_price'])) {
            $query->where('cars.price_gross', '>=', (float) $filters['min_price']);
        }
        if (isset($filters['max_price']) && is_numeric($filters['max_price'])) {
            $query->where('cars.price_gross', '<=', (float) $filters['max_price']);
        }

        $daysExpr = $this->daysSinceEntryExpr();

        if (isset($filters['min_days_in_stock'])) {
            $minDays = (int) $filters['min_days_in_stock'];
            $query->whereRaw("{$daysExpr} >= ?", [$minDays]);
        }
        if (isset($filters['max_days_in_stock'])) {
            $maxDays = (int) $filters['max_days_in_stock'];
            $query->whereRaw("{$daysExpr} <= ?", [$maxDays]);
        }

        // price_signal vive no `car_market_aggregates` como cálculo derivado;
        // não é coluna física. Para filtrar por price_signal precisamos de
        // recalcular a categoria com a fórmula do model em SQL.
        //
        // Implementação: JOIN ao último aggregate, calcula difference_percent
        // inline com CASE, depois filtra por categoria. Defesa: só junta se
        // o filtro estiver activo (evita custo para a maioria das queries).
        if (!empty($filters['price_signal']) && is_array($filters['price_signal'])) {
            $this->applyPriceSignalFilter($query, $filters['price_signal']);
        }

        if (!empty($filters['only_marked'])) {
            $query->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('car_promotion_priorities')
                    ->whereColumn('car_promotion_priorities.car_id', 'cars.id')
                    ->where('car_promotion_priorities.is_active', true);
            });
        }
    }

    /**
     * Subqueries de engagement — replica o padrão privado do `DashboardRepository`.
     * O scope `company_id` no inner aproveita o mesmo índice composto.
     */
    private function applyEngagementSubqueries(Builder $query, int $companyId): void
    {
        $query->addSelect([
            'views_count' => DB::table('car_views')
                ->selectRaw('COUNT(*)')
                ->whereColumn('car_views.car_id', 'cars.id')
                ->where('company_id', $companyId),
            'leads_count' => DB::table('car_leads')
                ->selectRaw('COUNT(*)')
                ->whereColumn('car_leads.car_id', 'cars.id')
                ->where('company_id', $companyId),
        ]);
    }

    /**
     * Ordenação — whitelist de colunas para evitar SQL injection via input.
     * `days_in_stock` é calculado on-the-fly (não há coluna física).
     *
     * "Score" como ordenação é o IPS — `LEFT JOIN` ao último score por car.
     * Defensivo contra pending (NULL): usa `score IS NULL DESC` por última
     * via ORDER BY duplo para não enganar o utilizador.
     */
    private function applySort(Builder $query, array $filters): void
    {
        $sortBy  = $filters['sort_by']  ?? self::DEFAULT_SORT_BY;
        $sortDir = strtolower($filters['sort_dir'] ?? self::DEFAULT_SORT_DIR);
        $sortDir = in_array($sortDir, ['asc', 'desc'], true) ? $sortDir : self::DEFAULT_SORT_DIR;

        $daysExpr = $this->daysSinceEntryExpr();

        switch ($sortBy) {
            case 'days_in_stock':
                // COALESCE(car_created_at, created_at) replica a regra de "quando
                // a viatura entrou no stand" usada noutros sítios.
                $query->orderByRaw("{$daysExpr} {$sortDir}");
                break;
            case 'price':
                // NULL primeiro/último consoante direcção — para não enganar
                // o utilizador que filtra "preço mais alto" com price_gross null.
                if ($this->isSqlite()) {
                    $query->orderByRaw('(cars.price_gross IS NULL) ASC');
                } else {
                    $nullsLast = $sortDir === 'desc' ? 'IS NULL ASC' : 'IS NULL DESC';
                    $query->orderByRaw("cars.price_gross {$nullsLast}");
                }
                $query->orderBy('cars.price_gross', $sortDir);
                break;
            case 'views':
                $query->orderByRaw("views_count {$sortDir}");
                break;
            case 'leads':
                $query->orderByRaw("leads_count {$sortDir}");
                break;
            case 'ips':
                // `pending` (score NULL) cai sempre no fim, independente da
                // direcção — não enganar o utilizador que ordena por IPS desc.
                $query->leftJoin('car_sale_potential_scores as latest_ips', function ($join) {
                    $join->on('latest_ips.id', '=', DB::raw(
                        '(SELECT MAX(id) FROM car_sale_potential_scores WHERE car_id = cars.id)'
                    ));
                });
                if ($this->isSqlite()) {
                    $query->orderByRaw('(latest_ips.score IS NULL) ASC');
                } else {
                    $query->orderByRaw('latest_ips.score IS NULL ASC');
                }
                $query->orderBy('latest_ips.score', $sortDir);
                break;
            default:
                $query->orderByRaw("{$daysExpr} desc");
        }

        // Tie-breaker estável — paginação sem este garante ordering instável.
        $query->orderBy('cars.id', 'desc');
    }

    /**
     * Filtra por categoria de price_signal aplicando inline a mesma fórmula
     * do `CarMarketAggregate::priceSignal()`:
     *   - overpriced     : diff >= 10
     *   - slightly_high  : 3 <= diff < 10
     *   - fair           : -5 <= diff < 3
     *   - competitive    : diff < -5
     *
     * `effective_price` = COALESCE(promo_price_gross, car_price_gross).
     * Viaturas sem aggregate ou sem median ficam fora deste filtro (não dá
     * para calcular price_signal — coerente com a UI mostrar "—").
     */
    private function applyPriceSignalFilter(Builder $query, array $signals): void
    {
        $valid = array_intersect($signals, ['overpriced', 'slightly_high', 'fair', 'competitive']);
        if (empty($valid)) {
            return;
        }

        $query->whereExists(function ($sub) use ($valid) {
            $sub->select(DB::raw(1))
                ->from('car_market_aggregates as agg')
                ->whereColumn('agg.car_id', 'cars.id')
                ->whereRaw('agg.id = (SELECT MAX(id) FROM car_market_aggregates WHERE car_id = cars.id)')
                ->whereNotNull('agg.median_price')
                ->where('agg.median_price', '>', 0)
                ->where(function ($q) use ($valid) {
                    $clauses = [
                        'overpriced'    => '(COALESCE(agg.promo_price_gross, agg.car_price_gross) - agg.median_price) / agg.median_price * 100 >= 10',
                        'slightly_high' => '(COALESCE(agg.promo_price_gross, agg.car_price_gross) - agg.median_price) / agg.median_price * 100 >= 3 AND (COALESCE(agg.promo_price_gross, agg.car_price_gross) - agg.median_price) / agg.median_price * 100 < 10',
                        'fair'          => '(COALESCE(agg.promo_price_gross, agg.car_price_gross) - agg.median_price) / agg.median_price * 100 >= -5 AND (COALESCE(agg.promo_price_gross, agg.car_price_gross) - agg.median_price) / agg.median_price * 100 < 3',
                        'competitive'   => '(COALESCE(agg.promo_price_gross, agg.car_price_gross) - agg.median_price) / agg.median_price * 100 < -5',
                    ];
                    foreach ($valid as $signal) {
                        $q->orWhereRaw($clauses[$signal]);
                    }
                });
        });
    }

    /**
     * Expressão "dias entre HOJE e a entrada no stand" portável MariaDB/SQLite.
     * Mesma técnica do `CarSalePotentialScoreService::scoreModelHistory` —
     * MariaDB `DATEDIFF(today, x)` vs SQLite `julianday(today) - julianday(x)`.
     * Sem isto a suite de testes em sqlite rebenta com "no such function: DATEDIFF".
     */
    private function daysSinceEntryExpr(): string
    {
        $col = 'COALESCE(cars.car_created_at, cars.created_at)';
        return $this->isSqlite()
            ? "CAST((julianday(CURRENT_DATE) - julianday({$col})) AS INTEGER)"
            : "DATEDIFF(CURRENT_DATE, {$col})";
    }

    private function isSqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }
}
