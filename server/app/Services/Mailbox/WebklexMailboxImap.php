<?php

declare(strict_types=1);

namespace App\Services\Mailbox;

use App\Models\Mailbox;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;

/**
 * O IMAP real (webklex/php-imap, IMAP em PHP puro). Uma ligação por operação, fechada no fim.
 * Também em dev (E-mail 3b). Só liga a um nome que resolve para endereços públicos (MailboxHosts),
 * como o envio.
 */
class WebklexMailboxImap implements MailboxImap
{
    public function check(Mailbox $mailbox): ImapResult
    {
        return $this->with($mailbox, function (Client $client) {
            $list = $client->getConnection()->folders('', '*')->validatedData();

            return ImapResult::ok(SentFolder::find(array_map(fn ($f) => (array) ($f['flags'] ?? []), (array) $list)));
        });
    }

    public function append(Mailbox $mailbox, string $folder, string $raw): ImapResult
    {
        return $this->with($mailbox, function (Client $client) use ($folder, $raw) {
            $client->getConnection()->appendMessage($folder, $raw, ['\\Seen'])->validatedData();

            return ImapResult::ok();
        });
    }

    public function unseen(Mailbox $mailbox): ImapResult
    {
        return $this->with($mailbox, function (Client $client) {
            $status = $client->getConnection()->folderStatus('INBOX', ['UNSEEN', 'UIDVALIDITY'])->validatedData();

            return ImapResult::ok(null, (int) ($status['unseen'] ?? 0), isset($status['uidvalidity']) ? (int) $status['uidvalidity'] : null);
        });
    }

    public function folders(Mailbox $mailbox): ImapResult
    {
        return $this->with($mailbox, function (Client $client) {
            $list = (array) $client->getConnection()->folders('', '*')->validatedData();

            return ImapResult::data(array_map(fn ($path, $f) => ['path' => (string) $path, 'flags' => (array) ($f['flags'] ?? [])], array_keys($list), $list));
        });
    }

    public function page(Mailbox $mailbox, string $folder, int $page, int $perPage, string $filter = self::ALL): ImapResult
    {
        return $this->with($mailbox, function (Client $client) use ($folder, $page, $perPage, $filter) {
            $conn = $client->getConnection();
            $selected = (array) $conn->examineFolder($folder)->validatedData();
            $uids = array_map('intval', (array) $conn->search([self::criteria($filter)])->validatedData());
            rsort($uids);
            $slice = array_slice($uids, ($page - 1) * $perPage, $perPage);
            $items = [];
            if ($slice) {
                $headers = (array) $conn->headers($slice)->validatedData();
                $flags = (array) $conn->flags($slice)->validatedData();
                foreach ($slice as $uid) {
                    $items[] = ['uid' => $uid, 'header' => (string) ($headers[$uid] ?? ''), 'flags' => (array) ($flags[$uid] ?? [])];
                }
            }

            return ImapResult::data(['total' => count($uids), 'items' => $items], isset($selected['uidvalidity']) ? (int) $selected['uidvalidity'] : null);
        });
    }

    public function message(Mailbox $mailbox, string $folder, int $uid): ImapResult
    {
        return $this->with($mailbox, function (Client $client) use ($folder, $uid) {
            $conn = $client->getConnection();
            $selected = (array) $conn->examineFolder($folder)->validatedData();
            // A mensagem inteira, com BODY.PEEK (abrir não marca como lida: o ecrã marca à parte).
            $fetched = (array) $conn->fetch(['UID', 'BODY.PEEK[]'], [$uid])->validatedData();
            $raw = $fetched[$uid]['BODY[]'] ?? null;
            if (! is_string($raw) || $raw === '') {
                return ImapResult::failed('A mensagem já não existe na caixa.');
            }
            $flags = (array) $conn->flags([$uid])->validatedData();

            return ImapResult::data(['raw' => $raw, 'flags' => (array) ($flags[$uid] ?? [])], isset($selected['uidvalidity']) ? (int) $selected['uidvalidity'] : null);
        });
    }

    public function seen(Mailbox $mailbox, string $folder, int $uid, bool $seen): ImapResult
    {
        return $this->with($mailbox, function (Client $client) use ($folder, $uid, $seen) {
            $conn = $client->getConnection();
            $conn->selectFolder($folder)->validatedData();
            $conn->store(['\\Seen'], $uid, null, $seen ? '+' : '-')->validatedData();

            return ImapResult::ok();
        });
    }

    public function newInInbox(Mailbox $mailbox, ?int $afterUid, ?int $knownUidvalidity, int $limit): ImapResult
    {
        return $this->with($mailbox, function (Client $client) use ($afterUid, $knownUidvalidity, $limit) {
            $conn = $client->getConnection();
            $selected = (array) $conn->examineFolder('INBOX')->validatedData();
            $uidvalidity = isset($selected['uidvalidity']) ? (int) $selected['uidvalidity'] : null;
            $changed = $knownUidvalidity !== null && $uidvalidity !== null && $knownUidvalidity !== $uidvalidity;
            // Com o UIDVALIDITY mudado os UIDs antigos não valem: as dos últimos 7 dias (o fio evita duplicados).
            $criteria = $changed || $afterUid === null ? ['SINCE', now()->subDays(7)->format('d-M-Y')] : ['UID', ($afterUid + 1) . ':*'];
            $uids = array_values(array_filter(array_map('intval', (array) $conn->search($criteria)->validatedData()),
                fn (int $u) => $changed || $afterUid === null || $u > $afterUid));
            sort($uids);
            $uids = array_slice($uids, 0, $limit);
            $headers = $uids ? (array) $conn->headers($uids)->validatedData() : [];
            $items = [];
            foreach ($uids as $uid) {
                $items[$uid] = (string) ($headers[$uid] ?? '');
            }

            return ImapResult::data(['items' => $items], $uidvalidity);
        });
    }

    public function unseenUids(Mailbox $mailbox): ImapResult
    {
        return $this->with($mailbox, function (Client $client) {
            $conn = $client->getConnection();
            $selected = (array) $conn->examineFolder('INBOX')->validatedData();
            $uids = array_map('intval', (array) $conn->search(['UNSEEN'])->validatedData());

            return ImapResult::data(['uids' => $uids], isset($selected['uidvalidity']) ? (int) $selected['uidvalidity'] : null);
        });
    }

    public function openInbox(Mailbox $mailbox, ?int $afterUid, ?int $knownUidvalidity, int $newLimit, int $perPage, bool $withUnseenUids): ImapResult
    {
        return $this->with($mailbox, function (Client $client) use ($afterUid, $knownUidvalidity, $newLimit, $perPage, $withUnseenUids) {
            $conn = $client->getConnection();
            $selected = (array) $conn->examineFolder('INBOX')->validatedData();
            $uidvalidity = isset($selected['uidvalidity']) ? (int) $selected['uidvalidity'] : null;

            // As novas (para ligar as respostas), como em newInInbox.
            $changed = $knownUidvalidity !== null && $uidvalidity !== null && $knownUidvalidity !== $uidvalidity;
            $criteria = $changed || $afterUid === null ? ['SINCE', now()->subDays(7)->format('d-M-Y')] : ['UID', ($afterUid + 1) . ':*'];
            $newUids = array_values(array_filter(array_map('intval', (array) $conn->search($criteria)->validatedData()),
                fn (int $u) => $changed || $afterUid === null || $u > $afterUid));
            sort($newUids);
            $newUids = array_slice($newUids, 0, $newLimit);

            // A primeira página, como em page. E-mail 10: as com estrela no topo (até uma página, das
            // mais recentes); a lista e o total são os das sem estrela.
            $all = array_map('intval', (array) $conn->search(['UNFLAGGED'])->validatedData());
            rsort($all);
            $slice = array_slice($all, 0, $perPage);
            $flaggedAll = array_map('intval', (array) $conn->search(['FLAGGED'])->validatedData());
            rsort($flaggedAll);
            $starredSlice = array_slice($flaggedAll, 0, $perPage);
            $wanted = array_values(array_unique([...$slice, ...$starredSlice, ...$newUids]));
            $headers = $wanted ? (array) $conn->headers($wanted)->validatedData() : [];
            $listed = array_values(array_unique([...$slice, ...$starredSlice]));
            $flags = $listed ? (array) $conn->flags($listed)->validatedData() : [];
            $item = fn (int $uid) => ['uid' => $uid, 'header' => (string) ($headers[$uid] ?? ''), 'flags' => (array) ($flags[$uid] ?? [])];
            $items = array_map($item, $slice);
            $starred = array_map($item, $starredSlice);
            $new = [];
            foreach ($newUids as $uid) {
                $new[$uid] = (string) ($headers[$uid] ?? '');
            }

            // Os não lidos: o número (STATUS) e, quando a opção do Gmail está desligada, os UID.
            $status = (array) $conn->folderStatus('INBOX', ['UNSEEN'])->validatedData();
            $unseenUids = $withUnseenUids ? array_map('intval', (array) $conn->search(['UNSEEN'])->validatedData()) : null;

            return ImapResult::data(['new' => $new, 'total' => count($all), 'items' => $items, 'starred' => $starred, 'starred_total' => count($flaggedAll),
                'unseen' => (int) ($status['unseen'] ?? 0), 'unseen_uids' => $unseenUids], $uidvalidity);
        });
    }

    public function messages(Mailbox $mailbox, string $folder, array $uids): ImapResult
    {
        return $this->with($mailbox, function (Client $client) use ($folder, $uids) {
            $conn = $client->getConnection();
            $selected = (array) $conn->examineFolder($folder)->validatedData();
            // Um só FETCH para todas, com as marcas e o corpo (BODY.PEEK: ler não marca como lida).
            $fetched = (array) $conn->fetch(['UID', 'FLAGS', 'BODY.PEEK[]'], array_values(array_map('intval', $uids)))->validatedData();
            $out = [];
            foreach ($fetched as $uid => $data) {
                if (is_string($data['BODY[]'] ?? null) && $data['BODY[]'] !== '') {
                    $out[(int) $uid] = ['raw' => $data['BODY[]'], 'flags' => (array) ($data['FLAGS'] ?? [])];
                }
            }

            return ImapResult::data(['messages' => $out], isset($selected['uidvalidity']) ? (int) $selected['uidvalidity'] : null);
        });
    }

    public function seenMany(Mailbox $mailbox, string $folder, array $uids, bool $seen, bool $count): ImapResult
    {
        return $this->with($mailbox, function (Client $client) use ($folder, $uids, $seen, $count) {
            $conn = $client->getConnection();
            $selected = (array) $conn->selectFolder($folder)->validatedData();
            // Um só UID STORE para todas (o store() do pacote só aceita intervalos).
            $set = implode(',', array_map('intval', $uids));
            $conn->requestAndResponse('UID STORE', [$set, ($seen ? '+' : '-') . 'FLAGS.SILENT', '(\\Seen)'], true)->validatedData();
            if (! $count) {
                return ImapResult::data(['unseen' => null, 'unseen_uids' => null], isset($selected['uidvalidity']) ? (int) $selected['uidvalidity'] : null);
            }
            // Os não lidos da Entrada na mesma ligação (a pasta selecionada é a Entrada).
            $unseen = array_map('intval', (array) $conn->search(['UNSEEN'])->validatedData());

            return ImapResult::data(['unseen' => count($unseen), 'unseen_uids' => $unseen], isset($selected['uidvalidity']) ? (int) $selected['uidvalidity'] : null);
        });
    }

    public function flag(Mailbox $mailbox, string $folder, array $uids, bool $flagged): ImapResult
    {
        return $this->with($mailbox, function (Client $client) use ($folder, $uids, $flagged) {
            $conn = $client->getConnection();
            $conn->selectFolder($folder)->validatedData();
            $set = implode(',', array_map('intval', $uids));
            $conn->requestAndResponse('UID STORE', [$set, ($flagged ? '+' : '-') . 'FLAGS.SILENT', '(\\Flagged)'], true)->validatedData();

            return ImapResult::ok();
        });
    }

    public function move(Mailbox $mailbox, string $from, array $uids, string $to): ImapResult
    {
        return $this->with($mailbox, function (Client $client) use ($from, $uids, $to) {
            $conn = $client->getConnection();
            $capabilities = array_map(fn ($c) => strtoupper((string) $c), (array) $conn->getCapabilities()->validatedData());
            $conn->selectFolder($from)->validatedData();
            $set = implode(',', array_map('intval', $uids));
            if (in_array('MOVE', $capabilities, true)) {
                $conn->requestAndResponse('UID MOVE', [$set, $conn->escapeString($to)], true)->validatedData();
            } elseif (in_array('UIDPLUS', $capabilities, true)) {
                // Sem MOVE: copiar, marcar e apagar SÓ estas (UID EXPUNGE). Nunca um EXPUNGE geral.
                $conn->requestAndResponse('UID COPY', [$set, $conn->escapeString($to)], true)->validatedData();
                $conn->requestAndResponse('UID STORE', [$set, '+FLAGS.SILENT', '(\\Deleted)'], true)->validatedData();
                $conn->requestAndResponse('UID EXPUNGE', [$set], true)->validatedData();
            } else {
                return ImapResult::failed('O servidor desta caixa não permite mover mensagens com segurança (falta o MOVE ou o UIDPLUS no IMAP). Nada foi alterado.');
            }

            return ImapResult::data(['moved' => count($uids)]);
        });
    }

    public function findByMessageIds(Mailbox $mailbox, string $folder, array $messageIds): ImapResult
    {
        return $this->with($mailbox, function (Client $client) use ($folder, $messageIds) {
            $conn = $client->getConnection();
            $selected = (array) $conn->examineFolder($folder)->validatedData();
            $uids = [];
            foreach ($messageIds as $id) {
                $clean = '<' . trim(str_replace(['"', '\\', "\r", "\n"], '', (string) $id), '<> ') . '>';
                $found = array_map('intval', (array) $conn->search(['HEADER', 'Message-ID', '"' . $clean . '"'])->validatedData());
                array_push($uids, ...$found);
            }

            return ImapResult::data(['uids' => array_values(array_unique($uids))], isset($selected['uidvalidity']) ? (int) $selected['uidvalidity'] : null);
        });
    }

    /** O critério do SEARCH para o filtro da página. */
    private static function criteria(string $filter): string
    {
        return match ($filter) {
            self::FLAGGED => 'FLAGGED',
            self::UNFLAGGED => 'UNFLAGGED',
            default => 'ALL',
        };
    }

    /** @param callable(Client): ImapResult $work */
    private function with(Mailbox $mailbox, callable $work): ImapResult
    {
        if (MailboxHosts::publicAddress($mailbox->imap_host) === null) {
            return ImapResult::failed("O servidor {$mailbox->imap_host} não foi encontrado ou não é um servidor de e-mail público.");
        }
        $client = null;
        try {
            $client = (new ClientManager())->make([
                'host' => strtolower($mailbox->imap_host),
                'port' => $mailbox->imap_port,
                'protocol' => 'imap',
                'encryption' => $mailbox->imap_encryption === 'starttls' ? 'starttls' : 'ssl',
                'validate_cert' => true,
                'username' => $mailbox->username,
                'password' => (string) $mailbox->password,
                'timeout' => (int) config('mailboxes.timeout'),
            ]);
            $client->connect();

            return $work($client);
        } catch (Throwable $e) {
            Log::info('[Caixa de correio] IMAP falhou', ['mailbox_id' => $mailbox->id, 'type' => class_basename($e)]);

            return ImapResult::failed($this->describe($e, $mailbox));
        } finally {
            try {
                $client?->disconnect();
            } catch (Throwable) {
                // a ligação já caiu
            }
        }
    }

    /** O motivo em português, sem a palavra-passe nem o texto cru do servidor. */
    private function describe(Throwable $e, Mailbox $mailbox): string
    {
        $message = strtolower($e->getMessage());
        $where = "{$mailbox->imap_host}:{$mailbox->imap_port}";

        return match (true) {
            $e instanceof \Webklex\PHPIMAP\Exceptions\AuthFailedException || str_contains($message, 'authenticat') || str_contains($message, 'login')
                => 'O servidor IMAP recusou o utilizador ou a palavra-passe.' . ($mailbox->account_type === 'gmail' ? ' No Gmail, use uma palavra-passe de app e confirme que o IMAP está ativo.' : ''),
            str_contains($message, 'certificate') || str_contains($message, 'ssl') || str_contains($message, 'tls') || str_contains($message, 'crypto')
                => "Falhou a ligação segura (SSL/TLS) a {$where}. Confirme a porta e o tipo de segurança.",
            str_contains($message, 'timed out') || str_contains($message, 'timeout')
                => "O servidor {$where} não respondeu a tempo.",
            $e instanceof \Webklex\PHPIMAP\Exceptions\ConnectionFailedException || str_contains($message, 'connection')
                => "Não foi possível ligar a {$where}.",
            str_contains($message, 'trycreate') || str_contains($message, 'mailbox') || str_contains($message, 'folder')
                => 'A pasta de Enviados não existe no servidor.',
            default => 'O servidor IMAP não aceitou o pedido.',
        };
    }
}
