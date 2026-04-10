<?php

namespace App\Repositories\Contracts;

use App\Models\VehicleAttribute;

interface VehicleAttributeRepositoryInterface extends BaseRepositoryInterface
{
    public function findByCarId(int $carId): ?VehicleAttribute;

    public function createOrUpdate(int $carId, array $attributes): VehicleAttribute;
}
