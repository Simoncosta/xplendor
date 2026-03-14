<?php

namespace App\Repositories\Contracts;

use App\Models\CarPerformanceMetric;
use Illuminate\Database\Eloquent\Collection;

interface CarPerformanceMetricRepositoryInterface
{
    public function getForCar(
        int $carId,
        int $companyId,
        ?string $channel = null,
        ?string $from    = null,
        ?string $to      = null
    ): Collection;

    public function getSummary(int $carId, int $companyId): array;

    public function getSummaryByChannel(int $carId, int $companyId): Collection;

    public function create(array $data): CarPerformanceMetric;

    public function update(CarPerformanceMetric $metric, array $data): CarPerformanceMetric;

    public function findForCompany(int $metricId, int $companyId): ?CarPerformanceMetric;

    public function getPendingReview(int $companyId): Collection;
}
