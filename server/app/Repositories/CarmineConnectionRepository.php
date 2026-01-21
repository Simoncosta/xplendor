<?php

namespace App\Repositories;

use App\Models\CarmineConnection;
use App\Repositories\Contracts\CarmineConnectionRepositoryInterface;

class CarmineConnectionRepository extends BaseRepository implements CarmineConnectionRepositoryInterface
{
    public function __construct(CarmineConnection $model)
    {
        parent::__construct($model);
    }
}
