<?php

namespace App\Jobs;

use App\Services\Restaurant\RestaurantSignalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Recalcula os sinais da restauração (por exemplo, depois de confirmar as categorias das famílias). */
class RecomputeRestaurantSignalsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 600;

    public function __construct(public int $companyId) {}

    public function handle(RestaurantSignalService $signals): void
    {
        $signals->compute($this->companyId);
    }
}
