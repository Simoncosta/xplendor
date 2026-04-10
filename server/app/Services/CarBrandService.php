<?php

namespace App\Services;

use App\Repositories\Contracts\CarBrandRepositoryInterface;
use Illuminate\Support\Collection;

class CarBrandService extends BaseService
{
    public function __construct(protected CarBrandRepositoryInterface $carBrandRepository)
    {
        parent::__construct($carBrandRepository);
    }

    public function getBrandsByType(string $vehicleType): Collection
    {
        return $this->carBrandRepository->getBrandsByType($vehicleType);
    }
}
