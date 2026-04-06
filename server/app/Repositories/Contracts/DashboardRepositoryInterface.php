<?php

namespace App\Repositories\Contracts;

interface DashboardRepositoryInterface extends BaseRepositoryInterface
{
    public function getSummary(int $companyId): array;
    public function getTopInterestCars(int $companyId, int $limit = 5);
    public function getLowLeadCars(int $companyId, int $limit = 5);
    public function getOldestCars(int $companyId, int $limit = 5);
    public function getBestInterestRateCars(int $companyId, int $limit = 5);
    public function getCapitalSummary(int $companyId): array;
    public function getHighestStuckCapitalCars(int $companyId, int $limit = 5);
    public function getUrgentActionCars(int $companyId, int $limit = 5);
    public function getHighDemandOpportunityCars(int $companyId, int $limit = 5);
    public function getHighInterestLowConversionCars(int $companyId, int $limit = 5);
    public function getMarketingPerformance(int $companyId): array;
    public function getCompanyInsights(int $companyId): array;
    public function groupCarsByPersona(int $companyId): array;
}
