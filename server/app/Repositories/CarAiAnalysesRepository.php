<?php

namespace App\Repositories;

use App\Models\CarAiAnalysis;
use App\Models\CarBrand;
use App\Repositories\Contracts\CarAiAnalysesRepositoryInterface;

class CarAiAnalysesRepository extends BaseRepository implements CarAiAnalysesRepositoryInterface
{
    public function __construct(CarAiAnalysis $model)
    {
        parent::__construct($model);
    }
}
