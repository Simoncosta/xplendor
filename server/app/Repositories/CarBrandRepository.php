<?php

namespace App\Repositories;

use App\Models\CarBrand;
use App\Repositories\Contracts\CarBrandRepositoryInterface;
use Illuminate\Support\Collection;

class CarBrandRepository extends BaseRepository implements CarBrandRepositoryInterface
{
    public function __construct(CarBrand $model)
    {
        parent::__construct($model);
    }

    public function getBrandsByType(string $vehicleType): Collection
    {
        return $this->model
            ->where('vehicle_type', $vehicleType)
            ->orderBy('name')
            ->get();
    }
}
