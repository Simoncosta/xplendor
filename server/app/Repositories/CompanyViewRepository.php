<?php

namespace App\Repositories;

use App\Models\CompanyView;
use App\Repositories\Contracts\CompanyViewRepositoryInterface;

class CompanyViewRepository extends BaseRepository implements CompanyViewRepositoryInterface
{
    public function __construct(CompanyView $model)
    {
        parent::__construct($model);
    }
}
