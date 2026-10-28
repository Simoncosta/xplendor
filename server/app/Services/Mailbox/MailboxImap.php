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
    /** E-mail 10 · O filtro de uma página: todas, só as com estrela (\Flagged) ou só as sem estrela. */
    public const ALL = 'all';
    public const FLAGGED = 'flagged';
    public const UNFLAGGED = 'unflagged';

    /** Liga, autentica e lista as pastas; devolve a pasta de Enviados encontrada (ou null). */
    public function check(Mailbox $mailbox): ImapResult;

    /** Guarda a mensagem (MIME completo) na pasta dada, marcada como lida. */
    public function append(Mailbox $mailbox, string $folder, string $raw): ImapResult;

    /** Os não lidos da Entrada e o UIDVALIDITY (STATUS INBOX). */
    public function unseen(Mailbox $mailbox): ImapResult;

    /** E-mail 4 · As pastas (LIST): data = list<array{path: string, flags: list<string>}>. */
    public function folders(Mailbox $mailbox): ImapResult;

    /**
     * E-mail 4 · Uma página da pasta, das mais recentes para as mais antigas: data = {total, items:
     * list<array{uid: int, header: string, flags: list<string>}>}; uidvalidity no resultado.
     * E-mail 10: $filter = ALL, FLAGGED ou UNFLAGGED (o total é o das mensagens do filtro).
     */
    public function page(Mailbox $mailbox, string $folder, int $page, int $perPage, string $filter = self::ALL): ImapResult;

    /** E-mail 4 · Uma mensagem inteira (MIME): data = {raw: string, flags: list<string>}. */
    public function message(Mailbox $mailbox, string $folder, int $uid): ImapResult;

    /** E-mail 4 · Marcar como lida (true) ou por ler (false). */
    public function seen(Mailbox $mailbox, string $folder, int $uid, bool $seen): ImapResult;

    /**
     * E-mail 4 · As mensagens da Entrada depois do último UID visto (ou, com o UIDVALIDITY mudado, as
     * dos últimos dias): data = {items: array<int, string> uid → cabeçalho}, até $limit; uidvalidity.
     */
    public function newInInbox(Mailbox $mailbox, ?int $afterUid, ?int $knownUidvalidity, int $limit): ImapResult;

    /** E-mail 4 · Os UID das mensagens por ler da Entrada (UID SEARCH UNSEEN): data = {uids: list<int>}; uidvalidity. */
    public function unseenUids(Mailbox $mailbox): ImapResult;

    /**
     * E-mail 4 · Abrir a Entrada numa só ligação: as mensagens novas para o ReplyLinker (como em
     * newInInbox), a primeira página (como em page) e os não lidos (o número e, com $withUnseenUids,
     * os UID). data = {new: array<int, string>, total: int, items: list, unseen: int, unseen_uids: ?list<int>}.
     * E-mail 10: as com estrela ficam no topo da primeira página: items e total são os das mensagens
     * sem estrela, e starred (até $perPage, das mais recentes) e starred_total os das com estrela.
     */
    public function openInbox(Mailbox $mailbox, ?int $afterUid, ?int $knownUidvalidity, int $newLimit, int $perPage, bool $withUnseenUids): ImapResult;

    /**
     * E-mail 4b · Várias mensagens da mesma pasta numa só ligação (a conversa aberta): data = {messages:
     * array<int, array{raw: string, flags: list<string>}>}; uidvalidity. Cada comando custa ~0,5 s no
     * Gmail: uma ligação por mensagem tornava a abertura de uma conversa muito lenta.
     */
    public function messages(Mailbox $mailbox, string $folder, array $uids): ImapResult;

    /**
     * E-mail 4b · Marcar várias como lidas (ou por ler) numa só ligação e, na Entrada, contar logo os
     * não lidos na mesma ligação: data = {unseen: ?int, unseen_uids: ?list<int>}.
     */
    public function seenMany(Mailbox $mailbox, string $folder, array $uids, bool $seen, bool $count): ImapResult;

    /** E-mail 10 · Pôr (true) ou tirar a estrela (\Flagged; no Gmail é a estrela) a várias, numa só ligação. */
    public function flag(Mailbox $mailbox, string $folder, array $uids, bool $flagged): ImapResult;

    /**
     * E-mail 10 · Mover várias para outra pasta (o Lixo, ou de volta), numa só ligação: UID MOVE, ou
     * UID COPY e UID EXPUNGE só dessas (UIDPLUS); sem nenhum dos dois, recusa (nunca um EXPUNGE geral,
     * que apagaria de vez outras mensagens). data = {moved: int}.
     */
    public function move(Mailbox $mailbox, string $from, array $uids, string $to): ImapResult;

    /** E-mail 10 · Os UID das mensagens da pasta com estes Message-ID (para o "Desfazer"): data = {uids: list<int>}. */
    public function findByMessageIds(Mailbox $mailbox, string $folder, array $messageIds): ImapResult;
}
