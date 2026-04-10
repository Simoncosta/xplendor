<?php

namespace App\Repositories\Contracts;

use Illuminate\Support\Collection;

interface CarModelRepositoryInterface extends BaseRepositoryInterface
{
    public function getModelsByBrandAndType(int $brandId, string $vehicleType): Collection;
}
