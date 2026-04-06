<?php

namespace App\Services;

use App\Models\Car;
use App\Repositories\Contracts\CarSalePotentialScoreRepositoryInterface;
use App\Repositories\Contracts\DashboardRepositoryInterface;

class DashboardService extends BaseService
{
    public function __construct(
        protected DashboardRepositoryInterface               $dashboardRepository,
        protected CarSalePotentialScoreRepositoryInterface   $potentialScoreRepository,
        protected CarMarketingRoiService                     $carMarketingRoiService,
        protected SilentBuyerDetectionService                $silentBuyerDetectionService,
        protected StockIntelligenceService                   $stockIntelligenceService,
        protected CarIssueEngine                             $carIssueEngine,
        protected StockAdRankingService                      $stockAdRankingService,
    ) {}

    public function getDashboard(int $companyId): array
    {
        $urgentActionCars          = $this->dashboardRepository->getUrgentActionCars($companyId);
        $highDemandOpportunityCars = $this->dashboardRepository->getHighDemandOpportunityCars($companyId);

        // Enriquecer ambas as listas com o IPS mais recente de cada carro
        $carIds = collect($urgentActionCars)
            ->merge($highDemandOpportunityCars)
            ->pluck('id')
            ->unique()
            ->values()
            ->toArray();

        $ipsMap = $this->buildIpsMap($carIds, $companyId);

        $urgentActionCars = collect($urgentActionCars)->map(function ($car) use ($ipsMap) {
            $car->ips_score          = $ipsMap[$car->id]['score']          ?? null;
            $car->ips_classification = $ipsMap[$car->id]['classification'] ?? null;
            return $car;
        });

        $highDemandOpportunityCars = collect($highDemandOpportunityCars)->map(function ($car) use ($ipsMap) {
            $car->ips_score          = $ipsMap[$car->id]['score']          ?? null;
            $car->ips_classification = $ipsMap[$car->id]['classification'] ?? null;
            return $car;
        });

        return [
            'summary'                          => $this->dashboardRepository->getSummary($companyId),
            'top_interest_cars'                => $this->dashboardRepository->getTopInterestCars($companyId),
            'low_lead_cars'                    => $this->dashboardRepository->getLowLeadCars($companyId),
            'oldest_cars'                      => $this->dashboardRepository->getOldestCars($companyId),
            'best_interest_rate_cars'          => $this->dashboardRepository->getBestInterestRateCars($companyId),
            'capital_summary'                  => $this->dashboardRepository->getCapitalSummary($companyId),
            'highest_stuck_capital_cars'       => $this->dashboardRepository->getHighestStuckCapitalCars($companyId),
            'urgent_action_cars'               => $urgentActionCars,
            'immediate_actions'                => $this->carIssueEngine->getImmediateActions($companyId),
            'high_demand_opportunity_cars'     => $highDemandOpportunityCars,
            'high_interest_low_conversion_cars' => $this->dashboardRepository->getHighInterestLowConversionCars($companyId),
            'marketing_performance'            => $this->dashboardRepository->getMarketingPerformance($companyId),
            'marketing_roi'                    => $this->carMarketingRoiService->getCompanyMarketingRoi($companyId),
            'ads_priority_ranking'             => $this->stockAdRankingService->rankActiveCarsForAds($companyId),
            'insights'                         => $this->dashboardRepository->getCompanyInsights($companyId),
            'silent_buyers'                    => $this->silentBuyerDetectionService->getDashboardSummary($companyId),
            'stock_intelligence'               => $this->getStockIntelligenceSummary($companyId),
            'personas'                         => $this->dashboardRepository->groupCarsByPersona($companyId),
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Busca o último score de uma lista de carros numa única query.
     * Retorna um mapa [car_id => ['score' => int, 'classification' => string]].
     */
    private function buildIpsMap(array $carIds, int $companyId): array
    {
        if (empty($carIds)) {
            return [];
        }

        $map = [];

        foreach ($carIds as $carId) {
            $latest = $this->potentialScoreRepository->getLatest($carId, $companyId);

            if ($latest) {
                $map[$carId] = [
                    'score'          => $latest->score,
                    'classification' => $latest->classification,
                ];
            }
        }

        return $map;
    }

    private function getStockIntelligenceSummary(int $companyId): array
    {
        $candidates = Car::query()
            ->where('company_id', $companyId)
            ->whereIn('status', ['active', 'sold'])
            ->with(['brand:id,name', 'model:id,name'])
            ->withCount(['views', 'leads', 'interactions'])
            ->orderByDesc('views_count')
            ->orderByDesc('interactions_count')
            ->limit(18)
            ->get()
            ->filter(fn($car) => $car->brand && $car->model && $car->registration_year)
            ->unique(fn($car) => implode('|', [
                $car->brand?->name,
                $car->model?->name,
                $car->registration_year,
                $car->fuel_type,
                $car->transmission,
            ]))
            ->values();

        if ($candidates->isEmpty()) {
            return [
                'opportunities' => [],
                'saturated_segments' => [],
            ];
        }

        $analyses = $candidates
            ->map(function ($car) use ($companyId) {
                $result = $this->stockIntelligenceService->getOpportunityForSegment([
                    'company_id' => $companyId,
                    'brand' => $car->brand?->name,
                    'model' => $car->model?->name,
                    'fuel' => $car->fuel_type,
                    'gearbox' => $car->transmission,
                    'year_range' => [
                        'from' => (int) $car->registration_year,
                        'to' => (int) $car->registration_year,
                    ],
                    'color' => $car->exterior_color,
                ]);

                return [
                    'title' => $result['segment'] ?: trim(sprintf(
                        '%s %s %s %s %s',
                        $car->brand?->name ?? '',
                        $car->model?->name ?? '',
                        $car->registration_year ?? '',
                        $car->fuel_type ?? '',
                        $car->transmission ?? ''
                    )),
                    'insight' => $result['insight'],
                    'key_signal' => $result['missing_signals'][0]
                        ?? (((int) ($result['total_listings'] ?? 0) >= 30) ? 'high_supply' : 'balanced_supply'),
                    'avg_price' => $result['avg_price'],
                    'total_listings' => (int) ($result['total_listings'] ?? 0),
                    'opportunity_score' => (int) ($result['opportunity_score'] ?? 0),
                    'scarcity_score' => (int) ($result['scarcity_score'] ?? 0),
                ];
            })
            ->values();

        $opportunities = $analyses
            ->filter(fn($item) => $item['opportunity_score'] >= 60 && $item['total_listings'] <= 15)
            ->sortByDesc('opportunity_score')
            ->take(3)
            ->values()
            ->all();

        $saturatedSegments = $analyses
            ->filter(fn($item) => $item['total_listings'] >= 20 || $item['key_signal'] === 'high_supply')
            ->sortByDesc('total_listings')
            ->take(3)
            ->values()
            ->map(function ($item) {
                $item['insight'] = $item['total_listings'] >= 30
                    ? 'Alta oferta neste segmento — risco de rotação lenta.'
                    : $item['insight'];
                $item['key_signal'] = 'high_supply';
                return $item;
            })
            ->all();

        return [
            'opportunities' => $opportunities,
            'saturated_segments' => $saturatedSegments,
        ];
    }
}
