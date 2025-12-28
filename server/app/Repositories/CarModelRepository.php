<?php

namespace App\Repositories;

use App\Models\CarModel;
use App\Repositories\Contracts\CarModelRepositoryInterface;

class CarModelRepository extends BaseRepository implements CarModelRepositoryInterface
{
    public function __construct(CarModel $model)
    {
        parent::__construct($model);
    }
}
