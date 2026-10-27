<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Mailbox\MailRetention;
use Illuminate\Console\Command;

/**
 * E-mail 8 · A purga noturna das mensagens guardadas que passaram do prazo de retenção da empresa.
 * Interna: só apaga as cópias da XPLENDOR; as mensagens no servidor de e-mail não são tocadas.
 */
class PurgeMailRetentionCommand extends Command
{
    protected $signature = 'mailbox:purge-retention';

    protected $description = 'Apaga as mensagens de e-mail guardadas que passaram do prazo de retenção de cada empresa';

    public function handle(MailRetention $retention): int
    {
        $counts = $retention->purge();
        $this->info('Mensagens apagadas: ' . array_sum($counts) . ' (' . count($counts) . ' empresas)');

        return self::SUCCESS;
    }
}
