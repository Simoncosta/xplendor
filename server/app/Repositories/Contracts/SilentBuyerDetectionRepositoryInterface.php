<?php

namespace App\Repositories\Contracts;

use Illuminate\Support\Collection;

interface SilentBuyerDetectionRepositoryInterface
{
    public function detectByCompany(int $companyId, int $days = 30, int $limit = 100): Collection;

    public function detectByCar(int $companyId, int $carId, int $days = 30, int $limit = 50): Collection;
}
