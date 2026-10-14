<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Radar\Fit\RadarFitService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Radar: o encaixe numa empresa (no máximo 3 sinais por dia). */
class FitRadarSignalsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 180;
    public int $uniqueFor = 120;

    public function __construct(public readonly int $companyId) {}

    public function uniqueId(): string
    {
        return "radar-fit:{$this->companyId}";
    }

    public function handle(RadarFitService $fit): void
    {
        $fit->fit($this->companyId);
    }
}
