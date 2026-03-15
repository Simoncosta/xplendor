<?php

namespace App\Observers;

use App\Jobs\CalculateCarSalePotentialScoreJob;
use App\Models\CarImage;

class CarImageObserver
{
    public function created(CarImage $image): void
    {
        CalculateCarSalePotentialScoreJob::dispatch(
            carId: $image->car_id,
            companyId: $image->company_id,
            triggeredBy: 'image_added',
        );
    }
}
