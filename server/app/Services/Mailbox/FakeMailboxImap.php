<?php

declare(strict_types=1);

namespace App\Services\Mailbox;

use App\Models\Mailbox;

/**
 * O IMAP simulado (driver fake, nos testes): pastas, mensagens (MIME, por exemplo os .eml de
 * tests/Fixtures/mail), não lidos e as cópias guardadas em memória; nunca liga a nenhum servidor.
 * failWith simula um erro (já com o texto que a pessoa vê).
 */
class FakeMailboxImap implements MailboxImap
{
    /** @var array<string, list<string>> o LIST: nome → marcas */
    public static array $folders = [];

    /** @var array<string, array<int, array{raw: string, flags: list<string>}>> pasta → uid → mensagem */
    public static array $messages = [];

    public static int $unseen = 0;

    public static int $uidvalidity = 1;

    public static ?string $failWith = null;

    /** @var list<array{mailbox_id: int, folder: string, raw: string}> */
    public static array $appended = [];

    /** @var list<string> as operações pedidas (para os testes verem o que foi ao servidor) */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$folders = ['INBOX' => ['\\HasNoChildren'], 'INBOX.Sent' => ['\\HasNoChildren', '\\Sent'], 'INBOX.Trash' => ['\\Trash']];
        self::$messages = [];
        self::$unseen = 0;
        self::$uidvalidity = 1;
        self::$failWith = null;
        self::$appended = [];
        self::$calls = [];
    }

    /** Põe uma mensagem na pasta (o próximo UID). */
    public static function put(string $folder, string $raw, array $flags = []): int
    {
        $uid = (int) max(array_keys((self::$messages[$folder] ?? []) ?: [0 => null])) + 1;
        self::$messages[$folder][$uid] = ['raw' => $raw, 'flags' => $flags];

        return $uid;
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

    public function folders(Mailbox $mailbox): ImapResult
    {
        self::$calls[] = 'folders';
        if (self::$failWith !== null) {
            return ImapResult::failed(self::$failWith);
        }

        return ImapResult::data(array_map(fn ($path, $flags) => ['path' => (string) $path, 'flags' => $flags], array_keys(self::$folders), self::$folders));
    }

    public function page(Mailbox $mailbox, string $folder, int $page, int $perPage, string $filter = self::ALL): ImapResult
    {
        self::$calls[] = "page:{$folder}:{$page}" . ($filter === self::ALL ? '' : ":{$filter}");
        if (self::$failWith !== null) {
            return ImapResult::failed(self::$failWith);
        }
        $all = array_filter(self::$messages[$folder] ?? [], fn ($m) => match ($filter) {
            self::FLAGGED => in_array('\\Flagged', $m['flags'], true),
            self::UNFLAGGED => ! in_array('\\Flagged', $m['flags'], true),
            default => true,
        });
        krsort($all);
        $items = [];
        foreach (array_slice($all, ($page - 1) * $perPage, $perPage, true) as $uid => $m) {
            $items[] = ['uid' => $uid, 'header' => self::header($m['raw']), 'flags' => $m['flags']];
        }

        return ImapResult::data(['total' => count($all), 'items' => $items], self::$uidvalidity);
    }

    public function message(Mailbox $mailbox, string $folder, int $uid): ImapResult
    {
        self::$calls[] = "message:{$folder}:{$uid}";
        if (self::$failWith !== null) {
            return ImapResult::failed(self::$failWith);
        }
        $m = self::$messages[$folder][$uid] ?? null;

        return $m ? ImapResult::data(['raw' => $m['raw'], 'flags' => $m['flags']], self::$uidvalidity) : ImapResult::failed('A mensagem já não existe na caixa.');
    }

    public function seen(Mailbox $mailbox, string $folder, int $uid, bool $seen): ImapResult
    {
        self::$calls[] = "seen:{$folder}:{$uid}:" . ($seen ? '1' : '0');
        if (self::$failWith !== null) {
            return ImapResult::failed(self::$failWith);
        }
        if (! isset(self::$messages[$folder][$uid])) {
            return ImapResult::failed('A mensagem já não existe na caixa.');
        }
        $flags = array_values(array_diff(self::$messages[$folder][$uid]['flags'], ['\\Seen']));
        self::$messages[$folder][$uid]['flags'] = $seen ? [...$flags, '\\Seen'] : $flags;

        return ImapResult::ok();
    }

    public function newInInbox(Mailbox $mailbox, ?int $afterUid, ?int $knownUidvalidity, int $limit): ImapResult
    {
        self::$calls[] = 'new';
        if (self::$failWith !== null) {
            return ImapResult::failed(self::$failWith);
        }
        $changed = $knownUidvalidity !== null && $knownUidvalidity !== self::$uidvalidity;
        $items = [];
        foreach (self::$messages['INBOX'] ?? [] as $uid => $m) {
            if ($changed || $afterUid === null || $uid > $afterUid) {
                $items[$uid] = self::header($m['raw']);
            }
        }
        ksort($items);

        return ImapResult::data(['items' => array_slice($items, 0, $limit, true)], self::$uidvalidity);
    }

    public function unseenUids(Mailbox $mailbox): ImapResult
    {
        self::$calls[] = 'unseen-uids';
        if (self::$failWith !== null) {
            return ImapResult::failed(self::$failWith);
        }
        $uids = [];
        foreach (self::$messages['INBOX'] ?? [] as $uid => $m) {
            if (! in_array('\\Seen', $m['flags'], true)) {
                $uids[] = $uid;
            }
        }

        return ImapResult::data(['uids' => $uids], self::$uidvalidity);
    }

    /** Numa só "ligação": conta como uma operação (os testes veem que a Entrada abre com um só pedido). */
    public function openInbox(Mailbox $mailbox, ?int $afterUid, ?int $knownUidvalidity, int $newLimit, int $perPage, bool $withUnseenUids): ImapResult
    {
        if (self::$failWith !== null) {
            self::$calls[] = 'open-inbox';

            return ImapResult::failed(self::$failWith);
        }
        $before = self::$calls;
        $new = $this->newInInbox($mailbox, $afterUid, $knownUidvalidity, $newLimit);
        // E-mail 10: as com estrela no topo; a lista e o total são os das sem estrela.
        $page = $this->page($mailbox, 'INBOX', 1, $perPage, self::UNFLAGGED);
        $starred = $this->page($mailbox, 'INBOX', 1, $perPage, self::FLAGGED);
        $uids = $withUnseenUids ? $this->unseenUids($mailbox)->data['uids'] : null;
        self::$calls = [...$before, 'open-inbox'];

        return ImapResult::data(['new' => $new->data['items'], 'total' => $page->data['total'], 'items' => $page->data['items'],
            'starred' => $starred->data['items'], 'starred_total' => $starred->data['total'],
            'unseen' => self::$unseen, 'unseen_uids' => $uids], self::$uidvalidity);
    }

    public function messages(Mailbox $mailbox, string $folder, array $uids): ImapResult
    {
        self::$calls[] = "messages:{$folder}:" . implode(',', $uids);
        if (self::$failWith !== null) {
            return ImapResult::failed(self::$failWith);
        }
        $out = [];
        foreach ($uids as $uid) {
            if (isset(self::$messages[$folder][$uid])) {
                $out[(int) $uid] = self::$messages[$folder][$uid];
            }
        }

        return ImapResult::data(['messages' => $out], self::$uidvalidity);
    }

    public function seenMany(Mailbox $mailbox, string $folder, array $uids, bool $seen, bool $count): ImapResult
    {
        self::$calls[] = "seen-many:{$folder}:" . implode(',', $uids) . ':' . ($seen ? '1' : '0');
        if (self::$failWith !== null) {
            return ImapResult::failed(self::$failWith);
        }
        foreach ($uids as $uid) {
            if (isset(self::$messages[$folder][$uid])) {
                $flags = array_values(array_diff(self::$messages[$folder][$uid]['flags'], ['\\Seen']));
                self::$messages[$folder][$uid]['flags'] = $seen ? [...$flags, '\\Seen'] : $flags;
            }
        }
        if (! $count) {
            return ImapResult::data(['unseen' => null, 'unseen_uids' => null], self::$uidvalidity);
        }
        $unseen = [];
        foreach (self::$messages['INBOX'] ?? [] as $uid => $m) {
            if (! in_array('\\Seen', $m['flags'], true)) {
                $unseen[] = $uid;
            }
        }

        return ImapResult::data(['unseen' => count($unseen), 'unseen_uids' => $unseen], self::$uidvalidity);
    }

    public function flag(Mailbox $mailbox, string $folder, array $uids, bool $flagged): ImapResult
    {
        self::$calls[] = "flag:{$folder}:" . implode(',', $uids) . ':' . ($flagged ? '1' : '0');
        if (self::$failWith !== null) {
            return ImapResult::failed(self::$failWith);
        }
        foreach ($uids as $uid) {
            if (isset(self::$messages[$folder][$uid])) {
                $flags = array_values(array_diff(self::$messages[$folder][$uid]['flags'], ['\\Flagged']));
                self::$messages[$folder][$uid]['flags'] = $flagged ? [...$flags, '\\Flagged'] : $flags;
            }
        }

        return ImapResult::ok();
    }

    public function move(Mailbox $mailbox, string $from, array $uids, string $to): ImapResult
    {
        self::$calls[] = "move:{$from}:" . implode(',', $uids) . ":{$to}";
        if (self::$failWith !== null) {
            return ImapResult::failed(self::$failWith);
        }
        if (! array_key_exists($to, self::$folders)) {
            return ImapResult::failed('A pasta de destino não existe na caixa.');
        }
        $moved = 0;
        foreach ($uids as $uid) {
            if (isset(self::$messages[$from][$uid])) {
                self::put($to, self::$messages[$from][$uid]['raw'], self::$messages[$from][$uid]['flags']);
                unset(self::$messages[$from][$uid]);
                $moved++;
            }
        }

        return ImapResult::data(['moved' => $moved]);
    }

    public function findByMessageIds(Mailbox $mailbox, string $folder, array $messageIds): ImapResult
    {
        self::$calls[] = "find:{$folder}:" . count($messageIds);
        if (self::$failWith !== null) {
            return ImapResult::failed(self::$failWith);
        }
        $wanted = array_map(fn ($id) => '<' . trim((string) $id, '<>') . '>', $messageIds);
        $uids = [];
        foreach (self::$messages[$folder] ?? [] as $uid => $m) {
            if (in_array(MailParser::thread(self::header($m['raw']))['message_id'], $wanted, true)) {
                $uids[] = $uid;
            }
        }

        return ImapResult::data(['uids' => $uids], self::$uidvalidity);
    }

    private static function header(string $raw): string
    {
        $parts = preg_split("/\r?\n\r?\n/", $raw, 2);

        return $parts[0] . "\r\n\r\n";
    }
}
