<?php

namespace App\Observers;

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
}
