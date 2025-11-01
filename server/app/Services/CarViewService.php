<?php

namespace App\Services;

use App\Repositories\Contracts\CarViewRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CarViewService extends BaseService
{
    public function __construct(protected CarViewRepositoryInterface $carViewRepository)
    {
        parent::__construct($carViewRepository);
    }
}
