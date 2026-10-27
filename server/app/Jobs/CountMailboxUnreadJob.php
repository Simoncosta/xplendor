<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Mailbox;
use App\Services\Mailbox\MailboxImap;
use App\Services\Mailbox\ReplyLinker;
use App\Services\Mailbox\UnreadCounter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * E-mail 3 · Conta os não lidos da Entrada de uma caixa (STATUS INBOX UNSEEN) e guarda o número, o
 * UIDVALIDITY e a hora. E-mail 4: antes, liga as respostas novas (ReplyLinker). Um job por caixa, na fila própria mail; único por caixa (não se acumulam
 * se o servidor estiver lento). O erro fica no estado do IMAP da caixa, com o motivo.
 */
class CountMailboxUnreadJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $mailboxId)
    {
        $this->onQueue('mail');
    }

    public function uniqueId(): string
    {
        return (string) $this->mailboxId;
    }

    public function handle(MailboxImap $imap, ?ReplyLinker $linker = null): void
    {
        $mailbox = Mailbox::find($this->mailboxId);
        if (! $mailbox) {
            return;
        }
        // E-mail 4: as respostas novas às conversas começadas na XPLENDOR ficam ligadas ao cliente.
        ($linker ?? app(ReplyLinker::class))->run($mailbox);
        // O número (E-mail 4: no Gmail com a opção desligada, sem as mensagens já abertas na XPLENDOR).
        (new UnreadCounter($imap))->refresh($mailbox->fresh());
    }
}
