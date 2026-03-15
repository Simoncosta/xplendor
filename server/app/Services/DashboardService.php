<?php

namespace App\Services;

use App\Repositories\Contracts\CarSalePotentialScoreRepositoryInterface;
use App\Repositories\Contracts\DashboardRepositoryInterface;
use Illuminate\Support\Collection;

class DashboardService extends BaseService
{
    public function __construct(
        protected DashboardRepositoryInterface               $dashboardRepository,
        protected CarSalePotentialScoreRepositoryInterface   $potentialScoreRepository,
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
            'high_demand_opportunity_cars'     => $highDemandOpportunityCars,
            'high_interest_low_conversion_cars' => $this->dashboardRepository->getHighInterestLowConversionCars($companyId),
            'marketing_performance'            => $this->dashboardRepository->getMarketingPerformance($companyId),
            'insights'                         => $this->dashboardRepository->getCompanyInsights($companyId),
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
}
