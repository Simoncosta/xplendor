<?php

namespace App\Repositories\Contracts;

use Carbon\CarbonInterface;

interface CarViewRepositoryInterface extends BaseRepositoryInterface
{
    public function countByCar(int $carId): int;

    public function countByCarSince(int $carId, CarbonInterface $since): int;

    public function groupByChannel(int $carId);

    public function getTimelineByCar(int $carId);

    public function getGroupedTimelineByCar(int $carId);
}
