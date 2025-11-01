<?php

namespace App\Repositories;

use App\Models\CarLog;
use App\Repositories\Contracts\CarLogRepositoryInterface;

class CarLogRepository extends BaseRepository implements CarLogRepositoryInterface
{
    public function __construct(CarLog $model)
    {
        parent::__construct($model);
    }
}
