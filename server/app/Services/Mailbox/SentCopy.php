<?php

declare(strict_types=1);

namespace App\Services\Mailbox;

use App\Models\Mailbox;

/**
 * E-mail 3 · Depois de cada envio pela caixa, guarda a cópia em Enviados (APPEND), para a mensagem
 * aparecer no webmail e no telemóvel como se tivesse saído de lá. Não guarda quando a caixa não o
 * pede, no smtp.gmail.com (o Gmail já a guarda) nem quando a mensagem foi para o Mailpit (dev).
 * Sem pasta de Enviados conhecida, procura-a primeiro (LIST).
 */
class SentCopy
{
    public function __construct(private readonly MailboxImap $imap) {}

    /** O motivo por que a cópia falhou (null se ficou guardada ou se não era para guardar). */
    public function store(Mailbox $mailbox, MailboxSendResult $sent): ?string
    {
        if (! $sent->ok || ! $sent->delivered || $sent->raw === null || ! $mailbox->save_to_sent || $mailbox->keepsSentCopyItself()) {
            return null;
        }
        if (! $mailbox->sent_folder) {
            $check = $this->imap->check($mailbox);
            if (! $check->ok || ! $check->sentFolder) {
                return $check->error ?? 'Não foi encontrada a pasta de Enviados na caixa.';
            }
            $mailbox->forceFill(['sent_folder' => $check->sentFolder])->save();
        }
        $result = $this->imap->append($mailbox, (string) $mailbox->sent_folder, $sent->raw);

        return $result->ok ? null : $result->error;
    }
}
