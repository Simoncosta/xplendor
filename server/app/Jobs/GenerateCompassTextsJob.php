<?php

namespace App\Jobs;

use App\Services\Restaurant\RestaurantCompassService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Bússola: depois de recalcular os sinais, gera as frases "O quê" das jogadas (fora do pedido). */
class GenerateCompassTextsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;

    public function __construct(public int $companyId) {}

    public function handle(RestaurantCompassService $compass): void
    {
        $compass->generateTexts($this->companyId);
    }
}
