<?php

namespace App\Repositories;

use App\Models\CarBrand;
use App\Repositories\Contracts\CarBrandRepositoryInterface;

class CarBrandRepository extends BaseRepository implements CarBrandRepositoryInterface
{
    public function __construct(CarBrand $model)
    {
        parent::__construct($model);
    }
}
