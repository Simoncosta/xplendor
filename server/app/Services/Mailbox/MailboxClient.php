<?php

declare(strict_types=1);

namespace App\Services\Mailbox;

use App\Models\Mailbox;

/**
 * E-mail 1 · Quem fala com o servidor de e-mail de uma caixa. A implementação vem de
 * config('mailboxes.driver'): smtp (SmtpMailboxClient), fake (FakeMailboxClient, nos testes) ou
 * disabled (DisabledMailboxClient). Nunca lança: o erro vem no resultado, já em português e sem
 * a palavra-passe.
 */
interface MailboxClient
{
    public function send(Mailbox $mailbox, OutgoingMail $mail): MailboxSendResult;
}
