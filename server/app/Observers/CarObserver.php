<?php

namespace App\Observers;

use App\Models\Car;
use Illuminate\Support\Str;

class CarObserver
{
    public function creating(Car $car)
    {
        if (empty($car->slug)) {
            $car->slug = Str::slug("{$car->mark}-{$car->model}-{$car->version}-" . uniqid());
        }
    }

    public function updating(Car $car)
    {
        if ($car->isDirty(['mark', 'model', 'version']) && empty($car->slug)) {
            $car->slug = Str::slug("{$car->mark}-{$car->model}-{$car->version}-" . uniqid());
        }
    }
}
