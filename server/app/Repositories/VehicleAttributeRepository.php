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

    public function getByCarId(int $carId): ?array
    {
        return $this->model->where('car_id', $carId)->first()?->attributes;
    }

    public function upsert(int $carId, array $attributes): VehicleAttribute
    {
        return $this->model->updateOrCreate(
            ['car_id' => $carId],
            ['attributes' => $attributes]
        );
    }
}
