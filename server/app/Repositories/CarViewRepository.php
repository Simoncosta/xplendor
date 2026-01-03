<?php

namespace App\Repositories;

use App\Models\CarView;
use App\Repositories\Contracts\CarViewRepositoryInterface;

class CarViewRepository extends BaseRepository implements CarViewRepositoryInterface
{
    public function __construct(CarView $model)
    {
        parent::__construct($model);
    }
}
