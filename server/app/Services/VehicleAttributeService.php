<?php

namespace App\Services;

use App\Models\Car;
use App\Models\VehicleAttribute;
use App\Repositories\Contracts\VehicleAttributeRepositoryInterface;

class VehicleAttributeService extends BaseService
{
    public function __construct(
        protected VehicleAttributeRepositoryInterface $vehicleAttributeRepository,
    ) {
        parent::__construct($vehicleAttributeRepository);
    }

    public function getAttributes(Car $car): ?array
    {
        return $this->vehicleAttributeRepository->getByCarId($car->id);
    }

    public function setAttributes(Car $car, array $data): ?VehicleAttribute
    {
        if ($data === []) {
            $this->vehicleAttributeRepository->deleteByCarId($car->id);
            return null;
        }

        return $this->vehicleAttributeRepository->upsert($car->id, $data);
    }

    public function mergeAttributes(Car $car, array $data): VehicleAttribute
    {
        $currentAttributes = $this->getAttributes($car) ?? [];

        return $this->vehicleAttributeRepository->upsert(
            $car->id,
            array_merge($currentAttributes, $data)
        );
    }
}
