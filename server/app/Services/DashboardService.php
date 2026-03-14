<?php

namespace App\Services;

use App\Repositories\Contracts\DashboardRepositoryInterface;

class DashboardService extends BaseService
{
    public function __construct(
        protected DashboardRepositoryInterface $dashboardRepository
    ) {}

    public function getDashboard(int $companyId): array
    {
        return [
            'summary' => $this->dashboardRepository->getSummary($companyId),
            'top_interest_cars' => $this->dashboardRepository->getTopInterestCars($companyId),
            'low_lead_cars' => $this->dashboardRepository->getLowLeadCars($companyId),
            'oldest_cars' => $this->dashboardRepository->getOldestCars($companyId),
            'best_interest_rate_cars' => $this->dashboardRepository->getBestInterestRateCars($companyId),
            'capital_summary' => $this->dashboardRepository->getCapitalSummary($companyId),
            'highest_stuck_capital_cars' => $this->dashboardRepository->getHighestStuckCapitalCars($companyId),
            'urgent_action_cars' => $this->dashboardRepository->getUrgentActionCars($companyId),
            'high_demand_opportunity_cars' => $this->dashboardRepository->getHighDemandOpportunityCars($companyId),
            'high_interest_low_conversion_cars' => $this->dashboardRepository->getHighInterestLowConversionCars($companyId),
            'marketing_performance' => $this->dashboardRepository->getMarketingPerformance($companyId),
            'insights' => $this->dashboardRepository->getCompanyInsights($companyId),
        ];
    }
}
