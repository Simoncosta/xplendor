<?php

namespace App\Services;

use App\Repositories\Contracts\CarLogRepositoryInterface;
use App\Repositories\Contracts\CarRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CarLogService extends BaseService
{
    public function __construct(protected CarLogRepositoryInterface $carLogRepository)
    {
        parent::__construct($carLogRepository);
    }
}
