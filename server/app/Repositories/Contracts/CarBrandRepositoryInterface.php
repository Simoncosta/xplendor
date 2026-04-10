<?php

namespace App\Repositories\Contracts;

use Illuminate\Support\Collection;

interface CarBrandRepositoryInterface extends BaseRepositoryInterface
{
    public function getBrandsByType(string $vehicleType): Collection;
}
