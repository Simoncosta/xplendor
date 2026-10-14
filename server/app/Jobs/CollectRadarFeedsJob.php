<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Radar\RadarCollector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Radar: a recolha de 15 em 15 minutos das fontes ligadas (tarefa externa radar-collect-feeds:
 * desligada no dev pelo SCHEDULE_EXTERNAL_JOBS). Uma só em fila (uniqueFor 10 minutos) e sem
 * repetições: cada pedido conta para o teto de 4 por fonte e por hora.
 */
class CollectRadarFeedsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 120;
    public int $uniqueFor = 600;

    public function uniqueId(): string
    {
        return 'radar-collect-feeds';
    }

    public function handle(RadarCollector $collector): array
    {
        return $collector->collect(null, true, ['scheduled' => true]);
    }
}
