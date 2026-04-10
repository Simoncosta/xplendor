<?php

namespace App\Services;

use App\Repositories\Contracts\CarModelRepositoryInterface;
use Illuminate\Support\Collection;

class CarModelService extends BaseService
{
    public function __construct(protected CarModelRepositoryInterface $carModelRepository)
    {
        parent::__construct($carModelRepository);
    }

    public function getModelsByBrandAndType(int $brandId, string $vehicleType): Collection
    {
        return $this->carModelRepository->getModelsByBrandAndType($brandId, $vehicleType);
    }
}
