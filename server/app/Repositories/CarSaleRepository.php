<?php

namespace App\Repositories;

use App\Models\CarSale;
use App\Repositories\Contracts\CarSaleRepositoryInterface;

class CarSaleRepository extends BaseRepository implements CarSaleRepositoryInterface
{
    public function __construct(CarSale $model)
    {
        parent::__construct($model);
    }
}
