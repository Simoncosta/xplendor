<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Health\SystemHealth;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sinal de vida do worker: o scheduler põe-no na fila de minuto a minuto e só bate quando o worker
 * o corre. Único na fila (com o worker parado não se acumulam centenas).
 */
class WorkerHeartbeatJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $uniqueFor = 600;

    public function handle(): void
    {
        SystemHealth::beat('worker');
    }
}
