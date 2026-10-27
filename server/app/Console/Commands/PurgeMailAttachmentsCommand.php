<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Mailbox\MailAttachments;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * E-mail 5b · Apaga os anexos carregados há mais de 24 horas que nunca foram enviados (ou de um envio
 * interrompido). Interna: não liga a nenhum servidor de e-mail. Regista só quantos apagou.
 */
class PurgeMailAttachmentsCommand extends Command
{
    protected $signature = 'mailbox:purge-attachments';

    protected $description = 'Apaga os anexos de e-mail temporários com mais de 24 horas';

    public function handle(MailAttachments $attachments): int
    {
        $count = $attachments->purgeExpired();
        if ($count > 0) {
            Log::info('[Caixa de correio] Anexos temporários apagados', ['count' => $count]);
        }
        $this->info("Anexos apagados: {$count}");

        return self::SUCCESS;
    }
}
