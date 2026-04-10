<?php

namespace App\Services;

use App\Repositories\Contracts\CarCategoryRepositoryInterface;
use Illuminate\Support\Collection;

class CarCategoryService extends BaseService
{
    public function __construct(protected CarCategoryRepositoryInterface $carCategoryRepository)
    {
        parent::__construct($carCategoryRepository);
    }

    public function getCategoriesByType(string $vehicleType): Collection
    {
        return $this->carCategoryRepository->getCategoriesByType($vehicleType);
    }
}
