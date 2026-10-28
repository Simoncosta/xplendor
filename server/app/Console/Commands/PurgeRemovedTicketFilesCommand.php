<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SupportTickets\RemovedTicketFiles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Pedidos de suporte: apaga os ficheiros das faturas retiradas ou trocadas há mais de 30 dias
 * (config tickets.removed_files_days) e acerta o espaço da empresa. Regista só quantos apagou.
 */
class PurgeRemovedTicketFilesCommand extends Command
{
    protected $signature = 'support:purge-removed-files';

    protected $description = 'Apaga as faturas retiradas dos pedidos de suporte com o prazo cumprido';

    public function handle(RemovedTicketFiles $files): int
    {
        $count = $files->purgeExpired();
        if ($count > 0) {
            Log::info('[Suporte] Faturas retiradas apagadas', ['count' => $count]);
        }
        $this->info("Faturas apagadas: {$count}");

        return self::SUCCESS;
    }
}
