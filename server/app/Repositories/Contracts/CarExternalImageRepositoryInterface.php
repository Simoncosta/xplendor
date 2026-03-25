<?php

namespace App\Repositories\Contracts;

interface CarExternalImageRepositoryInterface extends BaseRepositoryInterface
{
    public function replaceForCar(int $carId, int $companyId, string $source, array $images): void;
}
