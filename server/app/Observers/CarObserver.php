<?php

namespace App\Observers;

use App\Jobs\CalculateCarSalePotentialScoreJob;
use App\Mail\CarSoldNotificationMail;
use App\Models\Car;
use App\Models\CarPerformanceMetric;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;

class CarObserver
{
    /**
     * Quando o status muda para 'sold':
     * → preenche time_to_sale_days em todos os registos de performance do carro.
     */
    public function updated(Car $car): void
    {
        $this->recalculatePotentialScoreIfPricingChanged($car);

        if (!$car->wasChanged('status') || $car->status !== 'sold' || $car->getOriginal('status') === 'sold') {
            return;
        }

        $this->fillTimesToSale($car);
        $this->sendSoldNotification($car);
    }

    private function fillTimesToSale(Car $car): void
    {
        $publishedAt = Carbon::parse($car->created_at);
        $soldAt      = Carbon::now();
        $days        = (int) $publishedAt->diffInDays($soldAt);

        // Atualiza todos os registos de performance deste carro
        CarPerformanceMetric::where('car_id', $car->id)
            ->whereNull('time_to_sale_days')
            ->update(['time_to_sale_days' => $days]);
    }

    private function sendSoldNotification(Car $car): void
    {
        $car->loadMissing(['company', 'brand', 'model']);

        Mail::to('simonfrtd@gmail.com')->send(new CarSoldNotificationMail($car));
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
