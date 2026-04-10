<?php

namespace App\Repositories;

use App\Models\CarModel;
use App\Repositories\Contracts\CarModelRepositoryInterface;
use Illuminate\Support\Collection;

class CarModelRepository extends BaseRepository implements CarModelRepositoryInterface
{
    public function __construct(CarModel $model)
    {
        parent::__construct($model);
    }

    public function getModelsByBrandAndType(int $brandId, string $vehicleType): Collection
    {
        return $this->model
            ->where('car_brand_id', $brandId)
            ->where('vehicle_type', $vehicleType)
            ->orderBy('name')
            ->get();
    }
}
