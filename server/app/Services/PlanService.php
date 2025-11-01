<?php

namespace App\Services;

use App\Models\Plan;
use App\Repositories\Contracts\PlanRepositoryInterface;

class PlanService extends BaseService
{
    public function __construct(protected PlanRepositoryInterface $planRepository)
    {
        parent::__construct($planRepository);
    }
}
