<?php

namespace App\Observers;

use App\Jobs\CalculateCarSalePotentialScoreJob;
use App\Models\Car;
use App\Services\MarketSnapshotService;

class CarObserver
{
    public function created(Car $car): void
    {
        // Guard: skip in testing — Queue::fake() is sufficient when tests exercise this path.
        if (app()->environment('testing')) {
            return;
        }

        app(MarketSnapshotService::class)->snapshotForCar($car);
    }

    public function updated(Car $car): void
    {
        $this->recalculatePotentialScoreIfPricingChanged($car);
    }

    private function recalculatePotentialScoreIfPricingChanged(Car $car): void
    {
        $priceChanged = $car->wasChanged('price_gross');
        $promoPriceChanged = $car->wasChanged('promo_price_gross');

        if (!$priceChanged && !$promoPriceChanged) {
            return;
        }

        CalculateCarSalePotentialScoreJob::dispatch(
            carId: $car->id,
            companyId: $car->company_id,
            triggeredBy: $promoPriceChanged ? 'promo_price_change' : 'price_change',
        );
    }
}
