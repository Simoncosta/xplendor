<?php

namespace App\Repositories\Contracts;

interface CarInteractionRepositoryInterface extends BaseRepositoryInterface
{
    public function countByCar(int $carId): int;

    public function groupByType(int $carId);

    public function getTimelineByCar(int $carId);
}
