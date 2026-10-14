<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Radar\Relevance\RadarRelevanceService;
use App\Services\Radar\RadarCollector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Radar: depois de cada recolha, a relevância por ramo (radar_relevancia) e, a seguir, o encaixe
 * em cada empresa com o módulo radar ligado. Com o Radar desligado (RADAR_ENABLED) não faz nada.
 */
class EvaluateRadarRelevanceJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;
    public int $uniqueFor = 300;

    public function uniqueId(): string
    {
        return 'radar-relevance';
    }

    public function handle(RadarRelevanceService $relevance): void
    {
        if (! RadarCollector::enabled()) {
            return;
        }
        $relevance->evaluate();
        DB::table('company_modules')->where('module_key', 'radar')->pluck('company_id')
            ->each(fn ($id) => FitRadarSignalsJob::dispatch((int) $id));
    }
}
