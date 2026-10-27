<?php

declare(strict_types=1);

namespace App\Services\Mailbox;

use App\Models\Customer;
use App\Models\Mailbox;
use App\Models\MailboxOpenedUid;
use App\Models\MailMessage;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * E-mail 4 · A caixa de entrada na XPLENDOR. A caixa vive no servidor de e-mail: as pastas, a lista e
 * as mensagens leem-se ao vivo por IMAP, com uma cache curta (pastas 5 min, lista 30 s, mensagem
 * 60 s; os anexos nunca), e nada se copia para a XPLENDOR além do que se liga a um cliente.
 * Abrir a Entrada faz uma só ligação: liga as respostas novas (ReplyLinker), lê a primeira página e
 * atualiza os não lidos (UnreadCounter: no Gmail com a opção desligada, sem contar as já abertas aqui).
 */
class MailboxInbox
{
    public const PER_PAGE = 25;

    /** As pastas especiais (marca do LIST, RFC 6154), pela ordem em que aparecem. */
    private const SPECIAL = ['inbox' => 'Entrada', 'sent' => 'Enviados', 'drafts' => 'Rascunhos', 'junk' => 'Spam', 'trash' => 'Lixo', 'archive' => 'Arquivo', 'all' => 'Todas'];

    public function __construct(private readonly MailboxImap $imap, private readonly ReplyLinker $linker, private readonly UnreadCounter $counter) {}

    /** @return array{ok: bool, error?: string, folders?: list<array{path: string, name: string, special: ?string}>} */
    public function folders(Mailbox $mailbox): array
    {
        $cached = Cache::get($this->key($mailbox, 'folders'));
        if (is_array($cached)) {
            return ['ok' => true, 'folders' => $cached];
        }
        $result = $this->imap->folders($mailbox);
        if (! $result->ok) {
            return ['ok' => false, 'error' => (string) $result->error];
        }
        $folders = [];
        foreach ($result->data as $f) {
            $flags = array_map('strtolower', (array) $f['flags']);
            if (in_array('\\noselect', $flags, true) || in_array('\\nonexistent', $flags, true)) {
                continue;
            }
            $special = strtoupper($f['path']) === 'INBOX' ? 'inbox' : null;
            foreach (['sent', 'drafts', 'junk', 'trash', 'archive', 'all'] as $s) {
                $special ??= in_array('\\' . $s, $flags, true) ? $s : null;
            }
            $special ??= SentFolder::find([$f['path'] => []]) !== null ? 'sent' : null; // sem a marca, o nome habitual
            $parts = preg_split('#[./]#', $f['path']);
            $folders[] = ['path' => $f['path'], 'name' => $special ? self::SPECIAL[$special] : (string) end($parts), 'special' => $special];
        }
        $order = array_flip(array_keys(self::SPECIAL));
        usort($folders, fn ($a, $b) => [$order[$a['special']] ?? 99, mb_strtolower($a['name'])] <=> [$order[$b['special']] ?? 99, mb_strtolower($b['name'])]);
        Cache::put($this->key($mailbox, 'folders'), $folders, 300);

        return ['ok' => true, 'folders' => $folders];
    }

    /** A pasta tem de ser uma das do LIST (nada de nomes inventados). */
    public function knows(Mailbox $mailbox, string $folder): bool
    {
        $folders = $this->folders($mailbox);

        return $folders['ok'] && in_array($folder, array_column($folders['folders'], 'path'), true);
    }

    /** @return array{ok: bool, error?: string, total?: int, page?: int, per_page?: int, items?: list<array>, unread?: ?int} */
    public function page(Mailbox $mailbox, string $folder, int $page): array
    {
        $page = max(1, $page);
        $key = $this->key($mailbox, "list:{$folder}:{$page}");
        $cached = Cache::get($key);
        if (! is_array($cached)) {
            if (strtoupper($folder) === 'INBOX' && $page === 1) {
                // Abrir a Entrada numa só ligação: as respostas novas, a primeira página e os não lidos.
                $result = $this->imap->openInbox($mailbox, $mailbox->last_uid, $mailbox->uidvalidity, ReplyLinker::BATCH, self::PER_PAGE, ! $mailbox->marksSeenOnServer());
                if ($result->ok) {
                    $this->linker->link($mailbox, (array) $result->data['new'], $result->uidvalidity);
                    $mailbox->forceFill(['unread_count' => $this->counter->fromServer($mailbox, (int) $result->data['unseen'], $result->data['unseen_uids'], $result->uidvalidity),
                        'unread_checked_at' => now(), 'imap_status' => Mailbox::STATUS_ACTIVE, 'imap_error' => null])->save();
                }
            } else {
                $result = $this->imap->page($mailbox, $folder, $page, self::PER_PAGE);
            }
            if (! $result->ok) {
                return ['ok' => false, 'error' => (string) $result->error];
            }
            // As conversas (MailThreads): cada mensagem leva "thread"; o ecrã agrupa-as como o Gmail.
            $items = MailThreads::assign(array_map(fn ($i) => MailParser::summary((int) $i['uid'], (string) $i['header'], (array) $i['flags']), (array) ($result->data['items'] ?? [])));
            $cached = ['total' => (int) ($result->data['total'] ?? 0), 'uidvalidity' => $result->uidvalidity,
                'items' => array_map(fn ($i) => array_diff_key($i, array_flip(['in_reply_to', 'references'])), $items)];
            Cache::put($key, $cached, 30);
        }
        // No Gmail com a opção desligada: as que a pessoa já abriu na XPLENDOR contam como lidas.
        if (! $mailbox->marksSeenOnServer()) {
            $opened = MailboxOpenedUid::set($mailbox, $folder, $cached['uidvalidity'] ?? null);
            $cached['items'] = array_map(fn ($i) => isset($opened[(int) $i['uid']]) ? ['seen' => true] + $i : $i, $cached['items']);
        }
        unset($cached['uidvalidity']);

        return ['ok' => true, 'page' => $page, 'per_page' => self::PER_PAGE, 'unread' => $mailbox->fresh()->unread_count] + $cached;
    }

    /** A mensagem inteira (sem o conteúdo dos anexos), com o cliente a que está ligada. */
    public function message(Mailbox $mailbox, string $folder, int $uid): array
    {
        $parsed = $this->parsed($mailbox, $folder, $uid);
        if (isset($parsed['error'])) {
            return ['ok' => false, 'error' => $parsed['error']];
        }

        return ['ok' => true, 'message' => $this->decorate($mailbox, $folder, $uid, $parsed)];
    }

    /**
     * E-mail 4b · A conversa aberta: todas as mensagens numa só ligação IMAP (as que não estão na cache
     * curta), pela ordem pedida. @param list<int> $uids
     */
    public function thread(Mailbox $mailbox, string $folder, array $uids): array
    {
        $parsed = [];
        $missing = [];
        foreach ($uids as $uid) {
            $cached = Cache::get($this->key($mailbox, "message:{$folder}:{$uid}"));
            is_array($cached) ? $parsed[$uid] = $cached : $missing[] = $uid;
        }
        if ($missing) {
            $result = $this->imap->messages($mailbox, $folder, $missing);
            if (! $result->ok) {
                return ['ok' => false, 'error' => (string) $result->error];
            }
            foreach ((array) $result->data['messages'] as $uid => $m) {
                $full = MailParser::full((string) $m['raw'], (array) $m['flags']);
                if ($full) {
                    $full['uidvalidity'] = $result->uidvalidity;
                    Cache::put($this->key($mailbox, "message:{$folder}:{$uid}"), $full, 60);
                    $parsed[(int) $uid] = $full;
                }
            }
        }
        $messages = [];
        foreach ($uids as $uid) {
            if (isset($parsed[$uid])) {
                $messages[] = $this->decorate($mailbox, $folder, $uid, $parsed[$uid]);
            }
        }

        return $messages ? ['ok' => true, 'messages' => $messages] : ['ok' => false, 'error' => 'As mensagens já não existem na caixa.'];
    }

    /**
     * E-mail 4b · Marcar várias como lidas (ou por ler) de uma vez: uma ligação IMAP para marcar e,
     * na Entrada, contar os não lidos (no Gmail com a opção desligada, guarda só os UID abertos).
     * @param list<int> $uids
     */
    public function seenMany(Mailbox $mailbox, string $folder, array $uids, bool $seen): array
    {
        $inbox = strtoupper($folder) === 'INBOX';
        if ($seen && ! $mailbox->marksSeenOnServer()) {
            $thread = $this->thread($mailbox, $folder, $uids); // da cache: a conversa acabou de abrir
            if (! $thread['ok']) {
                return $thread;
            }
            foreach ($uids as $uid) {
                $uidvalidity = Cache::get($this->key($mailbox, "message:{$folder}:{$uid}"))['uidvalidity'] ?? null;
                if ($uidvalidity !== null) {
                    MailboxOpenedUid::firstOrCreate(['mailbox_id' => $mailbox->id, 'folder' => $folder, 'uidvalidity' => (int) $uidvalidity, 'uid' => $uid]);
                }
            }
            if ($inbox) {
                $this->counter->refresh($mailbox);
            }
        } else {
            $result = $this->imap->seenMany($mailbox, $folder, $uids, $seen, $inbox);
            if (! $result->ok) {
                return ['ok' => false, 'error' => (string) $result->error];
            }
            if (! $seen) {
                MailboxOpenedUid::where('mailbox_id', $mailbox->id)->where('folder', $folder)->whereIn('uid', $uids)->delete();
            }
            if ($inbox && $result->data['unseen'] !== null) {
                $mailbox->forceFill(['unread_count' => $this->counter->fromServer($mailbox, (int) $result->data['unseen'], $result->data['unseen_uids'], $result->uidvalidity),
                    'unread_checked_at' => now()])->save();
            }
            $this->markCached($mailbox, $folder, $uids, $seen);
        }
        $this->forget($mailbox);

        return ['ok' => true, 'unread' => $mailbox->fresh()->unread_count];
    }

    /** O que o ecrã recebe de uma mensagem: lida conforme a opção do Gmail e o cliente a que está ligada. */
    private function decorate(Mailbox $mailbox, string $folder, int $uid, array $parsed): array
    {
        if (! $mailbox->marksSeenOnServer() && isset(MailboxOpenedUid::set($mailbox, $folder, $parsed['uidvalidity'] ?? null)[$uid])) {
            $parsed['seen'] = true;
        }
        $linked = $parsed['message_id']
            ? MailMessage::where('mailbox_id', $mailbox->id)->where('message_id', $parsed['message_id'])->whereNotNull('customer_id')->first()
            : null;
        $customer = $linked ? Customer::where('company_id', $mailbox->company_id)->find($linked->customer_id) : null;
        unset($parsed['uidvalidity']);

        return $parsed + ['uid' => $uid, 'folder' => $folder, 'customer' => $customer ? ['id' => $customer->id, 'name' => $customer->name] : null];
    }

    /**
     * Marcar como lida ou por ler. Lida: no servidor, quando a caixa o pede (cPanel, Outro, Gmail com a
     * opção ligada); no Gmail com a opção desligada, guarda só o UID aberto. Por ler: sempre no
     * servidor, e o UID aberto deixa de contar.
     */
    public function seen(Mailbox $mailbox, string $folder, int $uid, bool $seen): array
    {
        if ($seen && ! $mailbox->marksSeenOnServer()) {
            $parsed = $this->parsed($mailbox, $folder, $uid);
            if (isset($parsed['error'])) {
                return ['ok' => false, 'error' => $parsed['error']];
            }
            if (($parsed['uidvalidity'] ?? null) !== null) {
                MailboxOpenedUid::firstOrCreate(['mailbox_id' => $mailbox->id, 'folder' => $folder, 'uidvalidity' => (int) $parsed['uidvalidity'], 'uid' => $uid]);
            }
        } else {
            $result = $this->imap->seen($mailbox, $folder, $uid, $seen);
            if (! $result->ok) {
                return ['ok' => false, 'error' => (string) $result->error];
            }
            if (! $seen) {
                MailboxOpenedUid::where('mailbox_id', $mailbox->id)->where('folder', $folder)->where('uid', $uid)->delete();
            }
            $this->markCached($mailbox, $folder, [$uid], $seen);
        }
        $this->forget($mailbox);
        if (strtoupper($folder) === 'INBOX') {
            $this->counter->refresh($mailbox);
        }

        return ['ok' => true, 'unread' => $mailbox->fresh()->unread_count];
    }

    /** Um anexo, lido ao vivo da caixa (nunca fica guardado nem em cache). */
    public function attachment(Mailbox $mailbox, string $folder, int $uid, int $index): array
    {
        $result = $this->imap->message($mailbox, $folder, $uid);
        if (! $result->ok) {
            return ['ok' => false, 'error' => (string) $result->error];
        }
        $file = MailParser::attachment((string) ($result->data['raw'] ?? ''), $index);

        return $file ? ['ok' => true, 'file' => $file] : ['ok' => false, 'error' => 'O anexo já não existe na mensagem.'];
    }

    /** "Ligar a um cliente": a mensagem fica guardada (texto e HTML limpo, sem anexos), ligada ao cliente. */
    public function link(Mailbox $mailbox, User $user, string $folder, int $uid, Customer $customer): array
    {
        $shown = $this->message($mailbox, $folder, $uid);
        if (! $shown['ok']) {
            return $shown;
        }
        $m = $shown['message'];
        if (! $m['message_id']) {
            return ['ok' => false, 'error' => 'Esta mensagem não tem identificador (Message-ID) e não pode ser ligada.'];
        }
        $out = strcasecmp((string) $m['from']['address'], (string) $mailbox->email) === 0;
        $date = $m['date'] ? \Carbon\Carbon::parse($m['date']) : now();
        MailMessage::updateOrCreate(['mailbox_id' => $mailbox->id, 'message_id' => $m['message_id']], [
            'company_id' => $mailbox->company_id,
            'user_id' => $user->id,
            'direction' => $out ? MailMessage::OUT : MailMessage::IN,
            'in_reply_to' => $m['in_reply_to'],
            'references' => implode(' ', $m['references']),
            'from_address' => (string) $m['from']['address'],
            'to_address' => (string) ($m['to'][0]['address'] ?? ''),
            'subject' => mb_substr($m['subject'], 0, 500),
            'body_text' => $m['text'],
            'body_html' => $m['html'],
            'status' => $out ? MailMessage::STATUS_SENT : MailMessage::STATUS_RECEIVED,
            'sent_at' => $out ? $date : null,
            'received_at' => $out ? null : $date,
            'customer_id' => $customer->id,
        ]);
        Cache::forget($this->key($mailbox, "message:{$folder}:{$uid}"));

        return ['ok' => true, 'customer' => ['id' => $customer->id, 'name' => $customer->name]];
    }

    /** As respostas novas e os não lidos da Entrada (a tarefa de 5 em 5 minutos em produção). */
    public function refreshInbox(Mailbox $mailbox): void
    {
        $this->linker->run($mailbox);
        $this->counter->refresh($mailbox);
    }

    /** A mensagem lida e preparada (cache curta), com o UIDVALIDITY da pasta. @return array */
    private function parsed(Mailbox $mailbox, string $folder, int $uid): array
    {
        $key = $this->key($mailbox, "message:{$folder}:{$uid}");
        $parsed = Cache::get($key);
        if (is_array($parsed)) {
            return $parsed;
        }
        $result = $this->imap->message($mailbox, $folder, $uid);
        if (! $result->ok) {
            return ['error' => (string) $result->error];
        }
        $parsed = MailParser::full((string) ($result->data['raw'] ?? ''), (array) ($result->data['flags'] ?? []));
        if (! $parsed) {
            return ['error' => 'Não foi possível ler esta mensagem.'];
        }
        $parsed['uidvalidity'] = $result->uidvalidity;
        Cache::put($key, $parsed, 60);

        return $parsed;
    }

    /**
     * As chaves da cache curta. Só as listas mudam com "lida/por ler" (forget muda-lhes a versão): as
     * pastas ficam (5 min) e as mensagens guardadas têm a marca de lida atualizada no momento (markCached).
     */
    private function key(Mailbox $mailbox, string $what): string
    {
        $version = str_starts_with($what, 'list:') ? (int) Cache::get("mailbox:{$mailbox->id}:v", 0) : 'f';

        return "mailbox:{$mailbox->id}:{$version}:" . md5($what);
    }

    private function forget(Mailbox $mailbox): void
    {
        Cache::put("mailbox:{$mailbox->id}:v", (int) Cache::get("mailbox:{$mailbox->id}:v", 0) + 1, 3600);
    }

    /** @param list<int> $uids */
    private function markCached(Mailbox $mailbox, string $folder, array $uids, bool $seen): void
    {
        foreach ($uids as $uid) {
            $key = $this->key($mailbox, "message:{$folder}:{$uid}");
            $cached = Cache::get($key);
            if (is_array($cached)) {
                Cache::put($key, ['seen' => $seen] + $cached, 60);
            }
        }
    }
}
