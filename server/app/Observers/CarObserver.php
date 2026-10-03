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

    /**
     * Viatura apagada: o gasto Meta atribuído pela tag [id:N] NÃO se apaga (não há
     * cascade de propósito). Fica como "viatura removida": car_id null, tagged_car_id N.
     */
    public function deleted(Car $car): void
    {
        \App\Models\MetaAdCarSpendDaily::where('company_id', $car->company_id)
            ->where('car_id', $car->id)
            ->update(['car_id' => null]);
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
