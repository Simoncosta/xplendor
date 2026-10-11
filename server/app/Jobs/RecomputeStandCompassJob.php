<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Compass\Stand\StandSignalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Bússola do stand: recalcula os sinais de uma empresa (de noite e quando o stock, os preços,
 * o mercado ou os anúncios mudam). Um só por empresa em fila (várias gravações seguidas dão
 * um recálculo). Só com o interruptor ligado.
 */
class RecomputeStandCompassJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 300;
    public int $uniqueFor = 60;

    public function __construct(public int $companyId) {}

    public function uniqueId(): string
    {
        return "bussola-stand:{$this->companyId}";
    }

    public function handle(StandSignalService $signals): void
    {
        $signals->compute($this->companyId);
    }
}
