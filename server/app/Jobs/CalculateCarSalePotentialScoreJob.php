<?php

namespace App\Jobs;

use App\Models\Car;
use App\Services\CarSalePotentialScoreService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CalculateCarSalePotentialScoreJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 60;

    public function __construct(
        public readonly int    $carId,
        public readonly int    $companyId,
        public readonly string $triggeredBy = 'scheduled',
    ) {}

    public function handle(CarSalePotentialScoreService $service): void
    {
        $service->calculate($this->carId, $this->companyId, $this->triggeredBy);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('[IPS] Job falhou', [
            'car_id'      => $this->carId,
            'triggered_by' => $this->triggeredBy,
            'error'       => $e->getMessage(),
        ]);
    }
}
