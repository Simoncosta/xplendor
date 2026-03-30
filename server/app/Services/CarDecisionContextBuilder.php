<?php

namespace App\Services;

use App\Models\Car;
use App\Repositories\Contracts\CarDecisionRepositoryInterface;
use App\Repositories\Contracts\CarRepositoryInterface;

class CarDecisionContextBuilder
{
    public function __construct(
        protected CarRepositoryInterface $carRepository,
        protected CarDecisionRepositoryInterface $decisionRepository,
        protected CarMarketIntelligenceService $carMarketIntelligenceService,
    ) {}

    public function build(Car $car): array
    {
        $car->loadMissing(['brand:id,name', 'model:id,name']);

        $performance = $this->decisionRepository->getPerformanceSnapshot($car->id, $car->company_id);
        $smartAdsBase = $this->carRepository->getSmartAdsContext($car->id, $car->company_id);
        $marketIntelligence = $this->carMarketIntelligenceService->analyze($car);
        $stockBenchmark = $this->decisionRepository->getStockBenchmark($car);

        $performanceContext = [
            ...$performance,
            'spend_7d' => (float) ($smartAdsBase['spend'] ?? 0),
            'conversion_rate_7d' => (float) ($smartAdsBase['conversion_rate'] ?? 0),
            'cost_per_lead_7d' => $smartAdsBase['cost_per_lead'] !== null
                ? (float) $smartAdsBase['cost_per_lead']
                : null,
            'ips_score' => (int) ($smartAdsBase['ips_score'] ?? 0),
            'days_in_stock' => (int) ($smartAdsBase['days_in_stock'] ?? 0),
        ];

        return [
            'car' => [
                'id' => $car->id,
                'company_id' => $car->company_id,
                'status' => $car->status,
                'price_gross' => $car->price_gross !== null ? (float) $car->price_gross : null,
                'registration_year' => $car->registration_year,
                'fuel_type' => $car->fuel_type,
                'transmission' => $car->transmission,
                'segment' => $car->segment,
                'brand' => $car->brand?->name,
                'model' => $car->model?->name,
            ],
            'performance' => $performanceContext,
            'market_intelligence' => $marketIntelligence,
            'stock_benchmark' => $stockBenchmark,
            'historical_trend' => $this->buildHistoricalTrend($performanceContext),
            'data_quality' => $this->buildDataQuality($performanceContext, $stockBenchmark, $marketIntelligence),
        ];
    }

    private function buildHistoricalTrend(array $performance): array
    {
        $viewsPrevious7 = max(0, (int) $performance['views_14d'] - (int) $performance['views_7d']);
        $leadsPrevious7 = max(0, (int) $performance['leads_14d'] - (int) $performance['leads_7d']);
        $interactionsPrevious7 = max(0, (int) $performance['interactions_14d'] - (int) $performance['interactions_7d']);

        $interestRateCurrent = $this->resolveInterestRate(
            (int) $performance['views_7d'],
            (int) $performance['interactions_7d'],
            (int) $performance['leads_7d'],
        );

        $interestRatePrevious = $this->resolveInterestRate(
            $viewsPrevious7,
            $interactionsPrevious7,
            $leadsPrevious7,
        );

        return [
            'trend_views' => $this->resolveTrendLabel((int) $performance['views_7d'], $viewsPrevious7),
            'trend_leads' => $this->resolveTrendLabel((int) $performance['leads_7d'], $leadsPrevious7),
            'trend_interest' => $this->resolveRateTrendLabel($interestRateCurrent, $interestRatePrevious),
            'views_previous_7d' => $viewsPrevious7,
            'leads_previous_7d' => $leadsPrevious7,
            'interest_rate_7d' => $interestRateCurrent,
            'interest_rate_previous_7d' => $interestRatePrevious,
        ];
    }

    private function buildDataQuality(array $performance, array $benchmark, array $marketIntelligence): array
    {
        $viewsTotal = (int) ($performance['views_total'] ?? 0);
        $leadsTotal = (int) ($performance['leads_total'] ?? 0);
        $interactionsTotal = (int) ($performance['interactions_total'] ?? 0);
        $daysInStock = (int) ($performance['days_in_stock'] ?? 0);

        $signalVolume = min(
            100,
            (int) round(min(45, $viewsTotal * 0.35) + min(30, $interactionsTotal * 3) + min(25, $leadsTotal * 10))
        );

        return [
            'signal_volume_score' => $signalVolume,
            'days_live' => $daysInStock,
            'has_cold_start' => $viewsTotal < 30 && $leadsTotal === 0 && $daysInStock < 14,
            'internal_comparables_count' => (int) ($benchmark['comparables_count'] ?? 0),
            'market_comparables_count' => (int) ($marketIntelligence['competitors_count'] ?? 0),
        ];
    }

    private function resolveInterestRate(int $views, int $interactions, int $leads): float
    {
        if ($views <= 0) {
            return 0.0;
        }

        return round((($interactions + $leads) / $views) * 100, 2);
    }

    private function resolveTrendLabel(int $current, int $previous): string
    {
        if ($current < 5 && $previous < 5) {
            return 'insufficient_data';
        }

        if ($previous === 0) {
            return $current > 0 ? 'rising' : 'stable';
        }

        $deltaPct = (($current - $previous) / max(1, $previous)) * 100;

        if ($deltaPct >= 20) {
            return 'rising';
        }

        if ($deltaPct <= -20) {
            return 'falling';
        }

        return 'stable';
    }

    private function resolveRateTrendLabel(float $current, float $previous): string
    {
        if ($current < 1 && $previous < 1) {
            return 'insufficient_data';
        }

        if ($current >= $previous + 1.5) {
            return 'rising';
        }

        if ($current <= $previous - 1.5) {
            return 'falling';
        }

        return 'stable';
    }
}
