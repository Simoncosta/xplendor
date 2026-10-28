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
            // E-mail 10: a pasta "Com estrela" do Gmail (marca \Flagged) não aparece: o filtro
            // "Com estrela" da barra lateral faz esse papel, igual em todas as caixas.
            if (in_array('\\noselect', $flags, true) || in_array('\\nonexistent', $flags, true) || in_array('\\flagged', $flags, true)) {
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

    /**
     * Uma página da pasta. E-mail 10: na Entrada, as com estrela ficam no topo da primeira página
     * (starred, até uma página das mais recentes) e a paginação (items, total) corre só pelas sem
     * estrela, das mais recentes para as mais antigas; com $starred, só as com estrela da Entrada.
     *
     * @return array{ok: bool, error?: string, total?: int, page?: int, per_page?: int, items?: list<array>, starred?: list<array>, starred_total?: int, unread?: ?int}
     */
    public function page(Mailbox $mailbox, string $folder, int $page, bool $starred = false): array
    {
        $page = max(1, $page);
        $inbox = strtoupper($folder) === 'INBOX';
        $key = $this->key($mailbox, "list:{$folder}:{$page}" . ($starred ? ':starred' : ''));
        $cached = Cache::get($key);
        if (! is_array($cached)) {
            if ($starred) {
                $result = $this->imap->page($mailbox, $folder, $page, self::PER_PAGE, MailboxImap::FLAGGED);
            } elseif ($inbox && $page === 1) {
                // Abrir a Entrada numa só ligação: as respostas novas, a primeira página e os não lidos.
                $result = $this->imap->openInbox($mailbox, $mailbox->last_uid, $mailbox->uidvalidity, ReplyLinker::BATCH, self::PER_PAGE, ! $mailbox->marksSeenOnServer());
                if ($result->ok) {
                    $this->linker->link($mailbox, (array) $result->data['new'], $result->uidvalidity);
                    $mailbox->forceFill(['unread_count' => $this->counter->fromServer($mailbox, (int) $result->data['unseen'], $result->data['unseen_uids'], $result->uidvalidity),
                        'unread_checked_at' => now(), 'imap_status' => Mailbox::STATUS_ACTIVE, 'imap_error' => null])->save();
                }
            } else {
                $result = $this->imap->page($mailbox, $folder, $page, self::PER_PAGE, $inbox ? MailboxImap::UNFLAGGED : MailboxImap::ALL);
            }
            if (! $result->ok) {
                return ['ok' => false, 'error' => (string) $result->error];
            }
            $cached = ['total' => (int) ($result->data['total'] ?? 0), 'uidvalidity' => $result->uidvalidity,
                'items' => self::summaries((array) ($result->data['items'] ?? []))];
            if (isset($result->data['starred'])) {
                $cached['starred'] = self::summaries((array) $result->data['starred']);
                $cached['starred_total'] = (int) ($result->data['starred_total'] ?? 0);
            }
            Cache::put($key, $cached, 30);
        }
        // No Gmail com a opção desligada: as que a pessoa já abriu na XPLENDOR contam como lidas.
        if (! $mailbox->marksSeenOnServer()) {
            $opened = MailboxOpenedUid::set($mailbox, $folder, $cached['uidvalidity'] ?? null);
            foreach (['items', 'starred'] as $list) {
                if (isset($cached[$list])) {
                    $cached[$list] = array_map(fn ($i) => isset($opened[(int) $i['uid']]) ? ['seen' => true] + $i : $i, $cached[$list]);
                }
            }
        }
        unset($cached['uidvalidity']);

        return ['ok' => true, 'page' => $page, 'per_page' => self::PER_PAGE, 'unread' => $mailbox->fresh()->unread_count] + $cached;
    }

    /** As linhas da lista, agrupadas por conversa (MailThreads: cada uma leva "thread", como no Gmail). */
    private static function summaries(array $items): array
    {
        $parsed = MailThreads::assign(array_map(fn ($i) => MailParser::summary((int) $i['uid'], (string) $i['header'], (array) $i['flags']), $items));

        return array_map(fn ($i) => array_diff_key($i, array_flip(['in_reply_to', 'references'])), $parsed);
    }

    /**
     * E-mail 10 · Pôr ou tirar a estrela (\Flagged, a estrela do Gmail) a várias, numa só ligação.
     * @param list<int> $uids
     */
    public function flagMany(Mailbox $mailbox, string $folder, array $uids, bool $flagged): array
    {
        $result = $this->imap->flag($mailbox, $folder, $uids, $flagged);
        if (! $result->ok) {
            return ['ok' => false, 'error' => (string) $result->error];
        }
        foreach ($uids as $uid) {
            $key = $this->key($mailbox, "message:{$folder}:{$uid}");
            $cached = Cache::get($key);
            if (is_array($cached)) {
                Cache::put($key, ['flagged' => $flagged] + $cached, 60);
            }
        }
        $this->forget($mailbox);

        return ['ok' => true];
    }

    /**
     * E-mail 10 · A pasta do Lixo da caixa: a que o servidor marca com \Trash (no Gmail, o Lixo dele);
     * sem marca, a escolhida à mão nas definições da caixa (E-mail 10b), se ainda existir na caixa.
     */
    public function trashFolder(Mailbox $mailbox): ?string
    {
        return $this->markedTrash($mailbox) ?? ($mailbox->trash_folder && $this->knows($mailbox, $mailbox->trash_folder) ? $mailbox->trash_folder : null);
    }

    /** A pasta que o servidor marca com \Trash (null: nenhuma, ou a caixa não respondeu). */
    public function markedTrash(Mailbox $mailbox): ?string
    {
        $folders = $this->folders($mailbox);
        if (! $folders['ok']) {
            return null;
        }
        foreach ($folders['folders'] as $f) {
            if ($f['special'] === 'trash') {
                return $f['path'];
            }
        }

        return null;
    }

    /**
     * E-mail 10 · Apagar: mover para o Lixo da caixa, nunca apagar de vez. Sem Lixo (nem marcado com
     * \Trash pelo servidor, nem escolhido à mão nas definições), não se apaga e diz-se porquê; já no Lixo, também não (apagar de vez é no programa de e-mail).
     * A cópia ligada a um cliente na XPLENDOR (mail_messages) fica até ao fim da retenção: faz parte
     * do histórico do cliente. Devolve o que o "Desfazer" precisa (os Message-ID e as pastas).
     *
     * @param list<int> $uids @param list<string> $messageIds
     */
    public function trash(Mailbox $mailbox, string $folder, array $uids, array $messageIds): array
    {
        $trash = $this->trashFolder($mailbox);
        if ($trash === null) {
            return ['ok' => false, 'code' => 'no_trash_folder', 'error' => 'A sua caixa não tem uma pasta de Lixo: o servidor não marca nenhuma com \\Trash e ainda não escolheu uma nas definições da caixa. Por segurança, a XPLENDOR não apaga mensagens de vez: nada foi apagado.'];
        }
        if ($trash === $folder) {
            return ['ok' => false, 'error' => 'Estas mensagens já estão no Lixo. Para as apagar de vez, use o seu programa de e-mail.'];
        }
        $result = $this->imap->move($mailbox, $folder, $uids, $trash);
        if (! $result->ok) {
            return ['ok' => false, 'error' => (string) $result->error];
        }
        foreach ($uids as $uid) {
            Cache::forget($this->key($mailbox, "message:{$folder}:{$uid}"));
        }
        MailboxOpenedUid::where('mailbox_id', $mailbox->id)->where('folder', $folder)->whereIn('uid', $uids)->delete();
        $this->forget($mailbox);
        if (strtoupper($folder) === 'INBOX') {
            $this->counter->refresh($mailbox);
        }
        $ids = array_values(array_filter(array_map(fn ($id) => self::cleanId((string) $id), $messageIds)));

        return ['ok' => true, 'moved' => (int) ($result->data['moved'] ?? count($uids)), 'unread' => $mailbox->fresh()->unread_count,
            // Sem o Message-ID de todas, o "Desfazer" não as encontraria no Lixo: não se oferece.
            'undo' => count($ids) === count($uids) ? ['folder' => $folder, 'message_ids' => $ids] : null];
    }

    /**
     * E-mail 10 · "Desfazer": as mensagens voltam do Lixo para a pasta de onde saíram (encontradas
     * pelo Message-ID, porque no Lixo têm outros UID).
     * @param list<string> $messageIds
     */
    public function restore(Mailbox $mailbox, string $folder, array $messageIds): array
    {
        $trash = $this->trashFolder($mailbox);
        if ($trash === null || $trash === $folder) {
            return ['ok' => false, 'error' => 'Não foi possível desfazer: a caixa não tem uma pasta de Lixo.'];
        }
        $ids = array_values(array_filter(array_map(fn ($id) => self::cleanId((string) $id), $messageIds)));
        $found = $this->imap->findByMessageIds($mailbox, $trash, $ids);
        if (! $found->ok) {
            return ['ok' => false, 'error' => (string) $found->error];
        }
        $uids = (array) ($found->data['uids'] ?? []);
        if ($uids === []) {
            return ['ok' => false, 'error' => 'As mensagens já não estão no Lixo.'];
        }
        $result = $this->imap->move($mailbox, $trash, $uids, $folder);
        if (! $result->ok) {
            return ['ok' => false, 'error' => (string) $result->error];
        }
        $this->forget($mailbox);
        if (strtoupper($folder) === 'INBOX') {
            $this->counter->refresh($mailbox);
        }

        return ['ok' => true, 'restored' => (int) ($result->data['moved'] ?? count($uids)), 'unread' => $mailbox->fresh()->unread_count];
    }

    /** Um Message-ID com < >, sem aspas nem quebras (vai para um SEARCH no IMAP). */
    private static function cleanId(string $id): ?string
    {
        $id = trim(str_replace(['"', '\\', "\r", "\n", ' '], '', $id), '<>');

        return $id === '' || mb_strlen($id) > 500 ? null : '<' . $id . '>';
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
        $raw = (string) ($result->data['raw'] ?? '');
        $file = MailParser::attachment($raw, $index);

        // E-mail 7: o Message-ID vai junto (a fatura importada guarda de onde veio).
        return $file ? ['ok' => true, 'file' => $file, 'message_id' => MailParser::thread($raw)['message_id']] : ['ok' => false, 'error' => 'O anexo já não existe na mensagem.'];
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
