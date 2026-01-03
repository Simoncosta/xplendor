<?php

namespace App\Repositories;

use App\Models\CarLead;
use App\Repositories\Contracts\CarLeadRepositoryInterface;

class CarLeadRepository extends BaseRepository implements CarLeadRepositoryInterface
{
    public function __construct(CarLead $model)
    {
        parent::__construct($model);
    }
}
