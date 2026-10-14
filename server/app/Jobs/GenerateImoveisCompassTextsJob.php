<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Compass\Imoveis\ImoveisCompassTexts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Bússola do imobiliário: as frases da IA das jogadas (depois de cada recálculo). */
class GenerateImoveisCompassTextsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $companyId) {}

    public function handle(ImoveisCompassTexts $texts): void
    {
        $texts->generate($this->companyId);
    }
}
