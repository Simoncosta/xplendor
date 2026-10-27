<?php

declare(strict_types=1);

namespace App\Services\Mailbox;

use App\Models\Mailbox;

/**
 * O IMAP simulado (driver fake, nos testes): pastas, não lidos e as cópias guardadas em memória;
 * nunca liga a nenhum servidor. failWith simula um erro (já com o texto que a pessoa vê).
 */
class FakeMailboxImap implements MailboxImap
{
    /** @var array<string, list<string>> o LIST: nome → marcas */
    public static array $folders = ['INBOX' => ['\\HasNoChildren'], 'INBOX.Sent' => ['\\HasNoChildren', '\\Sent'], 'INBOX.Trash' => ['\\Trash']];

    public static int $unseen = 0;

    public static int $uidvalidity = 1;

    public static ?string $failWith = null;

    /** @var list<array{mailbox_id: int, folder: string, raw: string}> */
    public static array $appended = [];

    public static function reset(): void
    {
        self::$folders = ['INBOX' => ['\\HasNoChildren'], 'INBOX.Sent' => ['\\HasNoChildren', '\\Sent'], 'INBOX.Trash' => ['\\Trash']];
        self::$unseen = 0;
        self::$uidvalidity = 1;
        self::$failWith = null;
        self::$appended = [];
    }

    public function check(Mailbox $mailbox): ImapResult
    {
        return self::$failWith !== null ? ImapResult::failed(self::$failWith) : ImapResult::ok(SentFolder::find(self::$folders));
    }

    public function append(Mailbox $mailbox, string $folder, string $raw): ImapResult
    {
        if (self::$failWith !== null) {
            return ImapResult::failed(self::$failWith);
        }
        self::$appended[] = ['mailbox_id' => $mailbox->id, 'folder' => $folder, 'raw' => $raw];

        return ImapResult::ok();
    }

    public function unseen(Mailbox $mailbox): ImapResult
    {
        return self::$failWith !== null ? ImapResult::failed(self::$failWith) : ImapResult::ok(null, self::$unseen, self::$uidvalidity);
    }
}
