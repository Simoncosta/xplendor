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

    /** E-mail 4 · As pastas (LIST): data = list<array{path: string, flags: list<string>}>. */
    public function folders(Mailbox $mailbox): ImapResult;

    /**
     * E-mail 4 · Uma página da pasta, das mais recentes para as mais antigas: data = {total, items:
     * list<array{uid: int, header: string, flags: list<string>}>}; uidvalidity no resultado.
     */
    public function page(Mailbox $mailbox, string $folder, int $page, int $perPage): ImapResult;

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
     */
    public function openInbox(Mailbox $mailbox, ?int $afterUid, ?int $knownUidvalidity, int $newLimit, int $perPage, bool $withUnseenUids): ImapResult;
}
