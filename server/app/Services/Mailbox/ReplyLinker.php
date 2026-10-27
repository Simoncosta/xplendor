<?php

declare(strict_types=1);

namespace App\Services\Mailbox;

use App\Models\Mailbox;
use App\Models\MailMessage;

/**
 * E-mail 4 · A tarefa de leitura: as mensagens novas da Entrada que respondem a uma conversa
 * começada na XPLENDOR (o In-Reply-To ou as References apontam para o Message-ID de uma mensagem
 * enviada por uma caixa da empresa) ficam guardadas em mail_messages, ligadas ao mesmo cliente,
 * venda, lead e relatório. As outras não se guardam. Avança pelo último UID visto; com o
 * UIDVALIDITY mudado, relê os últimos dias (o Message-ID evita duplicados).
 */
class ReplyLinker
{
    public const BATCH = 50;

    public function __construct(private readonly MailboxImap $imap) {}

    /** @return int quantas respostas ficaram ligadas */
    public function run(Mailbox $mailbox): int
    {
        $new = $this->imap->newInInbox($mailbox, $mailbox->last_uid, $mailbox->uidvalidity, self::BATCH);

        return $new->ok ? $this->link($mailbox, (array) ($new->data['items'] ?? []), $new->uidvalidity) : 0;
    }

    /**
     * Liga as mensagens novas já lidas do servidor (uid → cabeçalho), por exemplo pela abertura da
     * Entrada numa só ligação (MailboxImap::openInbox). Só vai buscar a mensagem inteira das que ligam.
     */
    public function link(Mailbox $mailbox, array $items, ?int $uidvalidity): int
    {
        $linked = 0;
        foreach ($items as $uid => $header) {
            $thread = MailParser::thread((string) $header);
            $ids = array_values(array_filter([$thread['in_reply_to'], ...$thread['references']]));
            if (! $ids || ! $thread['message_id']) {
                continue;
            }
            $original = MailMessage::where('company_id', $mailbox->company_id)->where('direction', MailMessage::OUT)
                ->whereIn('message_id', $ids)->latest('id')->first();
            if (! $original || MailMessage::where('mailbox_id', $mailbox->id)->where('message_id', $thread['message_id'])->exists()) {
                continue;
            }
            $full = $this->imap->message($mailbox, 'INBOX', (int) $uid);
            $parsed = $full->ok ? MailParser::full((string) ($full->data['raw'] ?? '')) : null;
            if (! $parsed) {
                continue;
            }
            MailMessage::create([
                'company_id' => $mailbox->company_id,
                'mailbox_id' => $mailbox->id,
                'user_id' => $mailbox->user_id,
                'direction' => MailMessage::IN,
                'message_id' => $thread['message_id'],
                'in_reply_to' => $thread['in_reply_to'],
                'references' => implode(' ', $thread['references']),
                'from_address' => (string) ($parsed['from']['address'] ?? ''),
                'to_address' => (string) ($parsed['to'][0]['address'] ?? $mailbox->email),
                'subject' => mb_substr($parsed['subject'], 0, 500),
                'body_text' => $parsed['text'],
                'body_html' => $parsed['html'],
                'status' => MailMessage::STATUS_RECEIVED,
                'received_at' => $parsed['date'] ? \Carbon\Carbon::parse($parsed['date']) : now(),
                'customer_id' => $original->customer_id,
                'car_sale_id' => $original->car_sale_id,
                'car_lead_id' => $original->car_lead_id,
                'satisfaction_report_id' => $original->satisfaction_report_id,
            ]);
            $linked++;
        }
        $mailbox->forceFill([
            'last_uid' => $items ? max(array_map('intval', array_keys($items))) : $mailbox->last_uid,
            'uidvalidity' => $uidvalidity ?? $mailbox->uidvalidity,
        ])->save();

        return $linked;
    }
}
