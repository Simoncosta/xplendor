<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Car;

class VehicleTypeResolver
{
    public static function getType(Car $car): string
    {
        return $car->vehicle_type;
    }

    public static function isCar(Car $car): bool
    {
        return $car->vehicle_type === 'car';
    }

    public static function isMotorhome(Car $car): bool
    {
        return $car->vehicle_type === 'motorhome';
    }
}
