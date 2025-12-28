<?php

namespace App\Services;

use App\Repositories\Contracts\CarBrandRepositoryInterface;

class CarBrandService extends BaseService
{
    public function __construct(protected CarBrandRepositoryInterface $carBrandRepository)
    {
        parent::__construct($carBrandRepository);
    }
}
