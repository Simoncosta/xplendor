<?php

declare(strict_types=1);

namespace App\Services\Mailbox;

use App\Models\Mailbox;
use App\Models\MailboxOpenedUid;

/**
 * E-mail 4 · O número de não lidos da Entrada. Quando abrir na XPLENDOR marca como lida no servidor
 * (cPanel, Outro e Gmail com a opção ligada), é o UNSEEN do servidor. No Gmail com a opção desligada,
 * são as mensagens por ler no servidor que a pessoa ainda não abriu na XPLENDOR (os UID guardados
 * em mailbox_opened_uids, no UIDVALIDITY atual; os de outro UIDVALIDITY apagam-se).
 */
class UnreadCounter
{
    public function __construct(private readonly MailboxImap $imap) {}

    /** O número a partir do que o servidor já devolveu (o STATUS e, se for preciso, os UID por ler). */
    public function fromServer(Mailbox $mailbox, int $statusUnseen, ?array $unseenUids, ?int $uidvalidity): int
    {
        if ($mailbox->marksSeenOnServer() || $unseenUids === null) {
            return $statusUnseen;
        }
        $this->prune($mailbox, $uidvalidity);
        $opened = MailboxOpenedUid::set($mailbox, 'INBOX', $uidvalidity);

        return count(array_filter($unseenUids, fn ($uid) => ! isset($opened[(int) $uid])));
    }

    /** Vai ao servidor e guarda o número na caixa. @return string|null o motivo, se falhar */
    public function refresh(Mailbox $mailbox): ?string
    {
        if ($mailbox->marksSeenOnServer()) {
            $r = $this->imap->unseen($mailbox);
            $count = $r->ok ? (int) $r->unseen : null;
        } else {
            $r = $this->imap->unseenUids($mailbox);
            $count = $r->ok ? $this->fromServer($mailbox, 0, (array) $r->data['uids'], $r->uidvalidity) : null;
        }
        if (! $r->ok) {
            $mailbox->forceFill(['imap_status' => Mailbox::STATUS_ERROR, 'imap_error' => $r->error])->save();

            return $r->error;
        }
        $mailbox->forceFill(['unread_count' => $count, 'unread_checked_at' => now(), 'imap_status' => Mailbox::STATUS_ACTIVE, 'imap_error' => null,
            'uidvalidity' => $mailbox->uidvalidity ?? $r->uidvalidity])->save();

        return null;
    }

    /** Os UID abertos com outro UIDVALIDITY já não valem (o servidor renumerou a caixa). */
    public function prune(Mailbox $mailbox, ?int $uidvalidity, string $folder = 'INBOX'): void
    {
        if ($uidvalidity !== null) {
            MailboxOpenedUid::where('mailbox_id', $mailbox->id)->where('folder', $folder)->where('uidvalidity', '!=', $uidvalidity)->delete();
        }
    }
}
