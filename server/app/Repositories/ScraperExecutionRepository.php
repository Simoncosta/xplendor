<?php

namespace App\Repositories;

use App\Models\ScraperExecution;
use App\Repositories\Contracts\ScraperExecutionRepositoryInterface;

class ScraperExecutionRepository extends BaseRepository implements ScraperExecutionRepositoryInterface
{
    public function __construct(ScraperExecution $model)
    {
        parent::__construct($model);
    }
}
