<?php

namespace App\Jobs;

use App\Models\Car;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RecalculateAllCarScoresJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 300;

    public function handle(): void
    {
        $cars = Car::where('status', 'active')
            ->select('id', 'company_id')
            ->get();

        Log::info('[IPS] Recalculo diário iniciado', ['total' => $cars->count()]);

        foreach ($cars as $car) {
            CalculateCarSalePotentialScoreJob::dispatch(
                carId: $car->id,
                companyId: $car->company_id,
                triggeredBy: 'scheduled',
            );
        }

        Log::info('[IPS] Jobs despachados', ['total' => $cars->count()]);
    }
}
