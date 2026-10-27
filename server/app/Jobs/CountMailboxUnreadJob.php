<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Mailbox;
use App\Services\Mailbox\MailboxImap;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * E-mail 3 · Conta os não lidos da Entrada de uma caixa (STATUS INBOX UNSEEN) e guarda o número, o
 * UIDVALIDITY e a hora. Um job por caixa, na fila própria mail; único por caixa (não se acumulam
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

    public function handle(MailboxImap $imap): void
    {
        $mailbox = Mailbox::find($this->mailboxId);
        if (! $mailbox) {
            return;
        }
        $result = $imap->unseen($mailbox);
        $mailbox->forceFill($result->ok
            ? ['unread_count' => $result->unseen, 'uidvalidity' => $result->uidvalidity ?? $mailbox->uidvalidity, 'unread_checked_at' => now(),
                'imap_status' => Mailbox::STATUS_ACTIVE, 'imap_error' => null]
            : ['imap_status' => Mailbox::STATUS_ERROR, 'imap_error' => $result->error])->save();
    }
}
