<?php

declare(strict_types=1);

namespace App\Services\Mailbox;

use App\Models\Mailbox;

/** Driver disabled: ninguém liga ao IMAP das caixas. */
class DisabledMailboxImap implements MailboxImap
{
    private const OFF = 'O IMAP das caixas de correio está desligado neste servidor.';

    public function check(Mailbox $mailbox): ImapResult
    {
        return ImapResult::failed(self::OFF);
    }

    public function append(Mailbox $mailbox, string $folder, string $raw): ImapResult
    {
        return ImapResult::failed(self::OFF);
    }

    public function unseen(Mailbox $mailbox): ImapResult
    {
        return ImapResult::failed(self::OFF);
    }
}
