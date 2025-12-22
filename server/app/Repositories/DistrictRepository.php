<?php

namespace App\Repositories;

use App\Models\District;
use App\Repositories\Contracts\DistrictRepositoryInterface;

class DistrictRepository extends BaseRepository implements DistrictRepositoryInterface
{
    public function __construct(District $model)
    {
        parent::__construct($model);
    }
}
