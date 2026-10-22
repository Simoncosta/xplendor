<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Health\HealthWatch;
use Illuminate\Console\Command;

/**
 * De 5 em 5 minutos (agendada, interna): base de dados, Redis, antivírus, disco e os sinais de vida
 * do scheduler e do worker. Aviso ao root quando uma falha dura mais de 10 minutos e quando volta.
 */
class HealthWatchCommand extends Command
{
    protected $signature = 'health:watch';

    protected $description = 'Vigia a saúde da XPLENDOR (base de dados, Redis, antivírus, disco, scheduler e worker) e avisa o root.';

    public function handle(HealthWatch $watch): int
    {
        $r = $watch->watch();
        $this->line(json_encode($r));

        return self::SUCCESS;
    }
}
