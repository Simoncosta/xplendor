<?php

namespace App\Services;

use App\Repositories\Contracts\CarViewRepositoryInterface;

class CarViewService extends BaseService
{
    public function __construct(protected CarViewRepositoryInterface $carViewRepository)
    {
        parent::__construct($carViewRepository);
    }
}
