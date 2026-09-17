<?php

namespace App\Repositories;

use App\Models\CompanyTask;
use App\Repositories\Contracts\CompanyTaskRepositoryInterface;

class CompanyTaskRepository extends BaseRepository implements CompanyTaskRepositoryInterface
{
    public function __construct(CompanyTask $model)
    {
        parent::__construct($model);
    }
}
