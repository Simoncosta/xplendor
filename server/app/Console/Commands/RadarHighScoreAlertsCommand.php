<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Radar\RadarHighScoreAlerts;
use Illuminate\Console\Command;

/**
 * Bússola 7: os avisos dos sinais de nota alta (sino e e-mail), de 15 em 15 minutos entre as 9 e as
 * 20 horas de Lisboa. O sino é interno; o e-mail segue a guarda das chamadas externas.
 */
class RadarHighScoreAlertsCommand extends Command
{
    protected $signature = 'radar:high-score-alerts';

    protected $description = 'Radar: avisa (sino e e-mail) dos sinais com nota alta, no máximo um por dia e por empresa.';

    public function handle(RadarHighScoreAlerts $alerts): int
    {
        $out = $alerts->run();
        $this->info($out['motivo'] ?? "Avisos: {$out['alerts']}; e-mails: {$out['emails']}.");

        return self::SUCCESS;
    }
}
