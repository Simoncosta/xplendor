<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Compass\Stand\StandCompassTexts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Bússola do stand: as frases "O quê" da IA (bussola_jogadas), depois de cada recálculo. */
class GenerateStandCompassTextsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;

    public function __construct(public int $companyId) {}

    public function handle(StandCompassTexts $texts): void
    {
        $texts->generate($this->companyId);
    }
}
