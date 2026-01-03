<?php

namespace App\Services;

use App\Repositories\Contracts\CarLeadRepositoryInterface;

class CarLeadService extends BaseService
{
    public function __construct(protected CarLeadRepositoryInterface $carLeadRepository)
    {
        parent::__construct($carLeadRepository);
    }
}
