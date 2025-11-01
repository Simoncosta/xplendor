<?php

namespace App\Repositories;

use App\Models\Car;
use App\Repositories\Contracts\CarRepositoryInterface;

class CarRepository extends BaseRepository implements CarRepositoryInterface
{
    public function __construct(Car $model)
    {
        parent::__construct($model);
    }
}
