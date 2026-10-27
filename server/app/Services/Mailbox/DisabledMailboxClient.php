<?php

declare(strict_types=1);

namespace App\Services\Mailbox;

use App\Models\Mailbox;

/** Driver disabled: ninguém envia pelas caixas. */
class DisabledMailboxClient implements MailboxClient
{
    public function send(Mailbox $mailbox, OutgoingMail $mail): MailboxSendResult
    {
        return MailboxSendResult::failed('O envio pelas caixas de correio está desligado neste servidor.');
    }
}
