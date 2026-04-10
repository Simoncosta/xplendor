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

    public function getAttributes(Car $car): array
    {
        $vehicleAttribute = $this->vehicleAttributeRepository->findByCarId($car->id);

        return $vehicleAttribute?->attributes ?? [];
    }

    public function setAttributes(Car $car, array $data): VehicleAttribute
    {
        return $this->vehicleAttributeRepository->createOrUpdate($car->id, $data);
    }

    public function mergeAttributes(Car $car, array $data): VehicleAttribute
    {
        $currentAttributes = $this->getAttributes($car);

        return $this->vehicleAttributeRepository->createOrUpdate(
            $car->id,
            array_merge($currentAttributes, $data)
        );
    }
}
