<?php

namespace App\Services;

use App\Repositories\Contracts\CarAiAnalysesRepositoryInterface;

class CarAiAnalysesService extends BaseService
{
    public function __construct(protected CarAiAnalysesRepositoryInterface $carAiAnalysesRepository)
    {
        parent::__construct($carAiAnalysesRepository);
    }
}
