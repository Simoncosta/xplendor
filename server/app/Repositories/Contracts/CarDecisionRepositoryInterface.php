<?php

namespace App\Repositories\Contracts;

use App\Models\Car;

interface CarDecisionRepositoryInterface
{
    public function getPerformanceSnapshot(int $carId, int $companyId): array;

    public function getStockBenchmark(Car $car): array;
}
