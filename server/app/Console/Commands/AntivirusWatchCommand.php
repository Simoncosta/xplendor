<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Financing\Antivirus\AntivirusHealth;
use Illuminate\Console\Command;

/**
 * De 5 em 5 minutos (agendada, interna): o antivírus responde? Se não responder há mais de 15
 * minutos, avisa o root uma vez; quando volta, avisa e põe na fila as análises em atraso.
 */
class AntivirusWatchCommand extends Command
{
    protected $signature = 'antivirus:watch';

    protected $description = 'Vigia o ClamAV: aviso ao root se estiver indisponível há mais de 15 minutos; volta a pôr na fila as análises em atraso.';

    public function handle(AntivirusHealth $health): int
    {
        $r = $health->watch();
        $this->line(json_encode($r));

        return self::SUCCESS;
    }
}
