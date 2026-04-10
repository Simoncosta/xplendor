<?php

namespace App\Repositories;

use App\Models\CarCategory;
use App\Repositories\Contracts\CarCategoryRepositoryInterface;
use Illuminate\Support\Collection;

class CarCategoryRepository extends BaseRepository implements CarCategoryRepositoryInterface
{
    public function __construct(CarCategory $model)
    {
        parent::__construct($model);
    }

    public function getCategoriesByType(string $vehicleType): Collection
    {
        return $this->model
            ->where('vehicle_type', $vehicleType)
            ->orderBy('name')
            ->get();
    }
}
