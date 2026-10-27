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

    public function folders(Mailbox $mailbox): ImapResult
    {
        return ImapResult::failed(self::OFF);
    }

    public function page(Mailbox $mailbox, string $folder, int $page, int $perPage): ImapResult
    {
        return ImapResult::failed(self::OFF);
    }

    public function message(Mailbox $mailbox, string $folder, int $uid): ImapResult
    {
        return ImapResult::failed(self::OFF);
    }

    public function seen(Mailbox $mailbox, string $folder, int $uid, bool $seen): ImapResult
    {
        return ImapResult::failed(self::OFF);
    }

    public function newInInbox(Mailbox $mailbox, ?int $afterUid, ?int $knownUidvalidity, int $limit): ImapResult
    {
        return ImapResult::failed(self::OFF);
    }

    public function unseenUids(Mailbox $mailbox): ImapResult
    {
        return ImapResult::failed(self::OFF);
    }

    public function openInbox(Mailbox $mailbox, ?int $afterUid, ?int $knownUidvalidity, int $newLimit, int $perPage, bool $withUnseenUids): ImapResult
    {
        return ImapResult::failed(self::OFF);
    }
}
