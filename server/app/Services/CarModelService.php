<?php

namespace App\Services;

use App\Models\Plan;
use App\Repositories\Contracts\CarModelRepositoryInterface;

class CarModelService extends BaseService
{
    public function __construct(protected CarModelRepositoryInterface $carModelRepository)
    {
        parent::__construct($carModelRepository);
    }
}
