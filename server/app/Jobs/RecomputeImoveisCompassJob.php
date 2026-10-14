<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Compass\Imoveis\ImoveisSignalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Bússola do imobiliário: recalcula os sinais de uma empresa (um por empresa em fila). */
class RecomputeImoveisCompassJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $uniqueFor = 60;

    public function __construct(public readonly int $companyId) {}

    public function uniqueId(): string
    {
        return "bussola-imoveis:{$this->companyId}";
    }

    public function handle(ImoveisSignalService $signals): void
    {
        $signals->compute($this->companyId);
    }
}
