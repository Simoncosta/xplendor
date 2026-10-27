<?php

declare(strict_types=1);

namespace App\Services\Mailbox;

use App\Models\Mailbox;

/**
 * E-mail 3 · Quem fala IMAP com o servidor de uma caixa: testar (e descobrir a pasta de Enviados),
 * guardar a cópia de uma mensagem enviada (APPEND) e contar os não lidos da Entrada (STATUS). A
 * implementação vem de config('mailboxes.driver'), como o envio: smtp (WebklexMailboxImap), fake
 * (FakeMailboxImap, nos testes) ou disabled. Nunca lança: o erro vem no resultado, em português.
 */
interface MailboxImap
{
    /** Liga, autentica e lista as pastas; devolve a pasta de Enviados encontrada (ou null). */
    public function check(Mailbox $mailbox): ImapResult;

    /** Guarda a mensagem (MIME completo) na pasta dada, marcada como lida. */
    public function append(Mailbox $mailbox, string $folder, string $raw): ImapResult;

    /** Os não lidos da Entrada e o UIDVALIDITY (STATUS INBOX). */
    public function unseen(Mailbox $mailbox): ImapResult;
}
