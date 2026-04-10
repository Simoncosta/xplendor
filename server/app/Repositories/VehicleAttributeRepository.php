<?php

namespace App\Repositories;

use App\Models\VehicleAttribute;
use App\Repositories\Contracts\VehicleAttributeRepositoryInterface;

class VehicleAttributeRepository extends BaseRepository implements VehicleAttributeRepositoryInterface
{
    public function __construct(VehicleAttribute $model)
    {
        parent::__construct($model);
    }

    public function findByCarId(int $carId): ?VehicleAttribute
    {
        return $this->model->where('car_id', $carId)->first();
    }

    public function createOrUpdate(int $carId, array $attributes): VehicleAttribute
    {
        return $this->model->updateOrCreate(
            ['car_id' => $carId],
            ['attributes' => $attributes]
        );
    }
}
