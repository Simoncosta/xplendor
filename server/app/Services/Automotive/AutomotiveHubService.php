<?php

declare(strict_types=1);

namespace App\Services\Automotive;

use App\Constants\StockThresholds;
use App\Models\Car;
use App\Models\Company;
use App\Models\MetaAd;
use App\Recommendations\RecommendationEngine;
use App\Recommendations\Rules\Automotive\DeadStockRule;
use App\Recommendations\Rules\Automotive\HighViewsNoContactsRule;
use App\Recommendations\Rules\Automotive\SoldCarAdStillActiveRule;
use App\Recommendations\Rules\Automotive\SpendWithoutLeadRule;
use App\Recommendations\Rules\Automotive\LowDemandRule;
use App\Recommendations\Rules\Automotive\PoorListingRule;
use App\Recommendations\Rules\Automotive\PriceAboveMarketRule;
use App\Repositories\CarAdSpendRepository;
use App\Repositories\DashboardRepository;
use App\Support\AutomotivePriceComparison;
use App\Support\PricePosition;
use App\Support\StockAge;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * XPLENDOR — Hub do Automóvel (dashboard do ramo).
 *
 * Só junta e apresenta: nada é recalculado aqui. Fontes únicas:
 *   · stock, dias médios e capital parado → DashboardRepository (StockAge,
 *     Car::IN_STOCK_STATUSES, StockThresholds);
 *   · posição de preço → PricePosition, com a salvaguarda de fallback
 *     (AutomotivePriceComparison: comparação aproximada nunca conta como acima);
 *   · gasto Meta → CarAdSpendRepository (por viatura) e ingestão por anúncio
 *     (stock geral = anúncios sem tag);
 *   · recomendações → motor genérico, ramo Automóvel.
 */
class AutomotiveHubService
{
    public const SPEND_WINDOW_DAYS = 30;
    public const FUNNEL_WINDOWS = [14, 30];
    public const RECOMMENDATIONS_LIMIT = 5;

    /** Ordem das regras por viatura: desempata quando a mesma viatura cumpre várias. */
    private const CAR_RULE_ORDER = [
        SoldCarAdStillActiveRule::KEY => 0,
        PriceAboveMarketRule::KEY => 1,
        DeadStockRule::KEY => 2,
        SpendWithoutLeadRule::KEY => 3,
        HighViewsNoContactsRule::KEY => 4,
        LowDemandRule::KEY => 5,
        PoorListingRule::KEY => 6,
    ];

    public function __construct(
        private readonly DashboardRepository $dashboard,
        private readonly CarAdSpendRepository $spend,
        private readonly RecommendationEngine $engine,
    ) {}

    // ── 1. RESUMO DO TOPO ──────────────────────────────────────────────────

    public function summary(int $companyId, ?CarbonImmutable $now = null): array
    {
        $now = $now ?? CarbonImmutable::now();
        $stock = $this->dashboard->getSummary($companyId);
        $capital = $this->dashboard->getCapitalSummary($companyId);

        return [
            'stock' => [
                'total_cars' => $stock['total_cars'],
                'own_stock' => $stock['own_stock'],
                'trade_ins' => $stock['trade_ins'],
                'avg_days_in_stock' => $stock['avg_days_in_stock'],
            ],
            'stuck_capital' => [
                'amount' => round($capital['stuck_capital_over_threshold'], 2),
                'total_capital' => round($capital['total_capital'], 2),
                'cars' => $this->stuckCarsCount($companyId),
                'thresholds' => [
                    'car' => StockThresholds::ageThresholdFor('car'),
                    'motorcycle' => StockThresholds::ageThresholdFor('motorcycle'),
                    'motorhome' => StockThresholds::ageThresholdFor('motorhome'),
                    'caravan' => StockThresholds::ageThresholdFor('caravan'),
                ],
            ],
            'price_position' => $this->pricePositionSummary($companyId),
            'meta_spend' => $this->metaSpendSummary($companyId, $now),
        ];
    }

    /** Viaturas paradas: mesmas expressões do capital parado (StockAge + limiar por tipo). */
    private function stuckCarsCount(int $companyId): int
    {
        $days = StockAge::sqlExpr('cars');
        $threshold = StockThresholds::sqlThresholdExpr('cars');

        return (int) Car::where('company_id', $companyId)
            ->whereIn('status', Car::IN_STOCK_STATUSES)
            ->where('is_resume', 0)
            ->whereRaw("{$days} >= {$threshold}")
            ->count();
    }

    /** Viaturas do stock próprio com a posição de preço (exata/aproximada). */
    private function inStockWithPrice(int $companyId)
    {
        return Car::where('company_id', $companyId)
            ->whereIn('status', Car::IN_STOCK_STATUSES)
            ->where('is_resume', 0)
            ->with(['brand:id,name', 'model:id,name', 'latestPricedMarketAggregate'])
            ->orderBy('id')
            ->get();
    }

    public static function priceFor(Car $car): array
    {
        $aggregate = PricePosition::latestAggregateFor($car);
        $p = AutomotivePriceComparison::fromPosition(PricePosition::compute($car, $aggregate), $aggregate);

        return [
            'position' => $p['position'],
            'display_position' => $p['display_position'],
            'comparison' => $p['comparison'],
            'counts_as_above_market' => $p['counts_as_above_market'],
            'difference_pct' => $p['difference_pct'],
            'confidence' => $p['confidence'],
            'comparables_count' => $p['comparables_count'],
            'median' => $p['median'],
            'effective_price' => $p['effective_price'],
        ];
    }

    /**
     * % acima do mercado: só comparações EXATAS com confiança média/alta. As
     * aproximadas e as de confiança baixa ficam contadas à parte (nunca "acima").
     */
    private function pricePositionSummary(int $companyId): array
    {
        $cars = $this->inStockWithPrice($companyId);

        $eligible = 0;
        $above = 0;
        $approximate = 0;
        $lowConfidence = 0;
        $noData = 0;
        $positions = [];

        foreach ($cars as $car) {
            $p = self::priceFor($car);
            $positions[] = ['car_id' => $car->id, 'car_title' => AutomotiveStockSnapshot::carTitle($car)] + $p;

            if ($p['comparison'] === null) {
                $noData++;
            } elseif ($p['comparison'] === AutomotivePriceComparison::APPROXIMATE) {
                $approximate++;
            } elseif (! PricePosition::meetsMinConfidence($p['confidence'])) {
                $lowConfidence++;
            } else {
                $eligible++;
                if ($p['counts_as_above_market']) {
                    $above++;
                }
            }
        }

        return [
            'above_market_pct' => $eligible > 0 ? round($above / $eligible * 100, 1) : null,
            'above_market_cars' => $above,
            'eligible_cars' => $eligible,
            'approximate_cars' => $approximate,
            'low_confidence_cars' => $lowConfidence,
            'no_data_cars' => $noData,
            'min_confidence' => PricePosition::MIN_CONFIDENCE_FOR_ABOVE,
            'positions' => $positions,
        ];
    }

    /**
     * Gasto Meta dos últimos 30 dias:
     *   · by_car        → atribuído a viaturas (fonte única CarAdSpendRepository);
     *   · general_stock → anúncios SEM tag (stock geral, intencional);
     *   · unattributed  → anúncios com tag inválida (ver avisos de qualidade).
     */
    private function metaSpendSummary(int $companyId, CarbonImmutable $now): array
    {
        $from = $now->subDays(self::SPEND_WINDOW_DAYS - 1)->toDateString();
        $to = $now->toDateString();

        $byCar = $this->spend->companyTotals($companyId, $from, $to)['spend'];
        $tagOnly = round((float) DB::table('meta_ad_car_spend_daily')
            ->where('company_id', $companyId)->whereBetween('date', [$from, $to])->sum('spend_allocated'), 2);

        $adLevel = $this->spend->hasAdLevelData($companyId);
        $byTagStatus = fn (array $statuses) => $this->spend->adLevelSpendByTagStatus($companyId, $from, $to, $statuses);

        return [
            'window_days' => self::SPEND_WINDOW_DAYS,
            'from' => $from,
            'to' => $to,
            'uses_tags' => $this->spend->usesTags($companyId),
            'ad_level_available' => $adLevel,
            'by_car' => $byCar,
            'by_car_tag' => $tagOnly,
            'by_car_manual_mapping' => round(max(0, $byCar - $tagOnly), 2),
            'general_stock' => $adLevel ? $byTagStatus([MetaAd::TAG_UNTAGGED]) : null,
            'unattributed' => $adLevel ? $byTagStatus([MetaAd::TAG_INVALID]) : null,
        ];
    }

    // ── 2. RECOMENDAÇÕES (top 5) ───────────────────────────────────────────

    /**
     * Recomendações do motor (ramo Automóvel). Por viatura fica só a de maior
     * prioridade (como o antigo "Viaturas que merecem atenção"); empate → ordem das
     * regras. Recomendações que não são de uma viatura passam tal como vêm.
     */
    public function recommendations(Company $company, ?CarbonImmutable $now = null, int $limit = self::RECOMMENDATIONS_LIMIT): array
    {
        $result = $this->engine->forCompany($company, 'automotive', $now);

        $byCar = [];
        $other = [];
        foreach ($result['recommendations'] as $r) {
            $carId = $r['evidence']['car_id'] ?? null;
            if ($carId === null || ! isset(self::CAR_RULE_ORDER[$r['rule_key']])) {
                $other[] = $r;
                continue;
            }
            $current = $byCar[$carId] ?? null;
            if ($current === null
                || $r['priority'] > $current['priority']
                || ($r['priority'] === $current['priority'] && self::CAR_RULE_ORDER[$r['rule_key']] < self::CAR_RULE_ORDER[$current['rule_key']])) {
                $byCar[$carId] = $r;
            }
        }

        $all = array_merge(array_values($byCar), $other);
        usort($all, fn ($a, $b) => [$b['priority'], $a['evidence']['car_id'] ?? PHP_INT_MAX]
            <=> [$a['priority'], $b['evidence']['car_id'] ?? PHP_INT_MAX]);

        return [
            'recommendations' => array_slice($all, 0, $limit),
            'total' => count($all),
            'notices' => $result['notices'],
        ];
    }

    // ── 3. FUNIL POR VIATURA ───────────────────────────────────────────────

    public function funnel(int $companyId, int $days, ?CarbonImmutable $now = null): array
    {
        $now = $now ?? CarbonImmutable::now();
        $from = $now->subDays($days - 1)->toDateString();
        $to = $now->toDateString();

        // Stock próprio + viaturas vendidas dentro da janela (o fim do funil).
        $cars = Car::where('company_id', $companyId)
            ->where('is_resume', 0)
            ->where(function ($q) use ($from) {
                $q->whereIn('status', Car::IN_STOCK_STATUSES)
                    ->orWhere(fn ($s) => $s->where('status', 'sold')->where('sold_at', '>=', $from));
            })
            ->with(['brand:id,name', 'model:id,name', 'latestPricedMarketAggregate'])
            ->orderBy('id')
            ->get();

        $carIds = $cars->pluck('id')->map(fn ($id) => (int) $id)->all();

        $funnel = $carIds === [] ? collect() : DB::table('car_funnel_metrics_daily')
            ->where('company_id', $companyId)
            ->whereIn('car_id', $carIds)
            ->whereBetween('date', [$from, $to])
            ->selectRaw('car_id, COALESCE(SUM(views), 0) as views, COALESCE(SUM(contacts), 0) as contacts, COALESCE(SUM(leads), 0) as leads')
            ->groupBy('car_id')
            ->get()
            ->keyBy('car_id');

        $paidLeads = $carIds === [] ? collect() : DB::table('car_leads')
            ->where('company_id', $companyId)
            ->whereIn('car_id', $carIds)
            ->where('channel', 'paid')
            ->where('created_at', '>=', $from . ' 00:00:00')
            ->where('created_at', '<=', $to . ' 23:59:59')
            ->selectRaw('car_id, COUNT(*) as n')
            ->groupBy('car_id')
            ->pluck('n', 'car_id');

        $spend = $this->spend->totalsByCar($companyId, $carIds, $from, $to);
        $adStatus = $this->adStatusByCar($companyId, $carIds);

        $rows = $cars->map(function (Car $car) use ($funnel, $paidLeads, $spend, $adStatus, $from, $now) {
            $f = $funnel->get($car->id);
            $carSpend = (float) ($spend[$car->id]['spend'] ?? 0);
            $paid = (int) ($paidLeads[$car->id] ?? 0);
            $soldAt = $car->status === 'sold' && $car->sold_at ? CarbonImmutable::parse($car->sold_at) : null;
            $price = self::priceFor($car);

            return [
                'car_id' => $car->id,
                'car_title' => AutomotiveStockSnapshot::carTitle($car),
                'status' => $car->status,
                'vehicle_type' => $car->vehicle_type,
                'days_in_stock' => (int) ($car->daysInStock($now) ?? 0),
                'price' => [
                    'effective_price' => $price['effective_price'],
                    'display_position' => $price['display_position'],
                    'comparison' => $price['comparison'],
                    'difference_pct' => $price['difference_pct'],
                    'confidence' => $price['confidence'],
                ],
                'views' => (int) ($f->views ?? 0),
                'contacts' => (int) ($f->contacts ?? 0),
                'leads' => (int) ($f->leads ?? 0),
                'paid_leads' => $paid,
                'sold' => $soldAt !== null && $soldAt->toDateString() >= $from,
                'sold_at' => $soldAt?->toDateString(),
                'paid_spend' => round($carSpend, 2),
                ...self::cpl($carSpend, $paid),
                'ad_status' => $adStatus[$car->id] ?? ['status' => 'none', 'active_ads' => 0],
            ];
        })->sortByDesc('days_in_stock')->values();

        $totSpend = round((float) $rows->sum('paid_spend'), 2);
        $totPaid = (int) $rows->sum('paid_leads');

        return [
            'days' => $days,
            'from' => $from,
            'to' => $to,
            'rows' => $rows->all(),
            'totals' => [
                'cars' => $rows->count(),
                'views' => (int) $rows->sum('views'),
                'contacts' => (int) $rows->sum('contacts'),
                'leads' => (int) $rows->sum('leads'),
                'paid_leads' => $totPaid,
                'sales' => $rows->where('sold', true)->count(),
                'paid_spend' => $totSpend,
                ...self::cpl($totSpend, $totPaid),
            ],
        ];
    }

    /**
     * CPL pago = gasto atribuído ÷ leads com channel='paid'. Nunca divide por zero:
     *   · gasto e leads  → cpl, estado 'ok';
     *   · gasto sem lead → cpl null, estado 'spend_without_lead';
     *   · sem gasto      → cpl null, estado 'no_spend'.
     *
     * @return array{cpl: ?float, cpl_state: string}
     */
    public static function cpl(float $spend, int $paidLeads): array
    {
        if ($spend <= 0) {
            return ['cpl' => null, 'cpl_state' => 'no_spend'];
        }
        if ($paidLeads <= 0) {
            return ['cpl' => null, 'cpl_state' => 'spend_without_lead'];
        }

        return ['cpl' => round($spend / $paidLeads, 2), 'cpl_state' => 'ok'];
    }

    /**
     * Estado do anúncio por viatura: 'active' (anúncio com tag ATIVO ou mapeamento
     * manual ativo), 'inactive' (tem anúncio, nenhum ativo) ou 'none'.
     */
    private function adStatusByCar(int $companyId, array $carIds): array
    {
        if ($carIds === []) {
            return [];
        }

        $active = $this->spend->activeCampaignsByCar($companyId, $carIds);

        $hasAd = [];
        MetaAd::where('company_id', $companyId)
            ->whereIn('tag_status', [MetaAd::TAG_MATCHED, MetaAd::TAG_SPLIT])
            ->get(['tag_car_ids'])
            ->each(function (MetaAd $ad) use (&$hasAd) {
                foreach ((array) $ad->tag_car_ids as $id) {
                    $hasAd[(int) $id] = true;
                }
            });
        DB::table('car_ad_campaigns')->where('company_id', $companyId)->whereIn('car_id', $carIds)
            ->pluck('car_id')->each(function ($id) use (&$hasAd) {
                $hasAd[(int) $id] = true;
            });

        $out = [];
        foreach ($carIds as $id) {
            $n = (int) ($active[$id] ?? 0);
            $out[$id] = [
                'status' => $n > 0 ? 'active' : (isset($hasAd[$id]) ? 'inactive' : 'none'),
                'active_ads' => $n,
            ];
        }

        return $out;
    }

    // ── 4. AVISOS DE QUALIDADE ─────────────────────────────────────────────

    public function warnings(int $companyId): array
    {
        $list = $this->spend->invalidTagWarnings($companyId);

        return [
            'invalid_tags' => [
                'count' => count($list),
                'items' => $list,
            ],
        ];
    }
}
