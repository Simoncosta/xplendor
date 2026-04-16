<?php

namespace App\Repositories\Contracts;

use App\Models\VehicleAttribute;

interface VehicleAttributeRepositoryInterface extends BaseRepositoryInterface
{
    public function getByCarId(int $carId): ?array;

    public function upsert(int $carId, array $attributes): VehicleAttribute;

    public function deleteByCarId(int $carId): void;
}
