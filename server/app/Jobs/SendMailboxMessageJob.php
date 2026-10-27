<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MailMessage;
use App\Services\Mailbox\MailAttachments;
use App\Services\Mailbox\MailboxClient;
use App\Services\Mailbox\OutgoingMail;
use App\Services\Mailbox\SentCopy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * E-mail 2 · Envia uma mensagem gravada (MailMessage) pela caixa de correio de quem a escreveu. Na
 * fila só vai o id: as credenciais decifram-se aqui, no worker (o Mail do Laravel não serve, porque
 * o transporte de cada caixa não sobrevive à fila). Uma só tentativa: repetir um envio SMTP pode
 * mandar a mensagem duas vezes; o erro fica na mensagem, com o motivo. Na fila própria mail (E-mail 3),
 * com o seu worker; depois do envio, a cópia em Enviados (SentCopy). E-mail 5b: com os anexos, cujos
 * ficheiros se apagam no fim (na mensagem fica só o nome e o tamanho).
 */
class SendMailboxMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 180; // E-mail 5b: com anexos (até 20 MB), o envio e a cópia em Enviados demoram mais

    public function __construct(public readonly int $messageId, public readonly string $fromName = '')
    {
        $this->onQueue('mail');
    }

    public function handle(MailboxClient $client, SentCopy $sentCopy, MailAttachments $attachments): void
    {
        $message = MailMessage::with('mailbox')->find($this->messageId);
        if (! $message || $message->status !== MailMessage::STATUS_QUEUED) {
            return; // já tratada (ou apagada)
        }
        if (! $message->mailbox) {
            $message->forceFill(['status' => MailMessage::STATUS_ERROR, 'error' => 'A caixa de correio foi removida antes do envio.'])->save();
            $attachments->purgeFor($message);

            return;
        }

        try {
            $this->deliver($message, $client, $sentCopy, $attachments->contents($message));
        } finally {
            // E-mail 5b: os ficheiros dos anexos apagam-se depois do envio (bem ou mal; uma só tentativa).
            $attachments->purgeFor($message);
        }
    }

    private function deliver(MailMessage $message, MailboxClient $client, SentCopy $sentCopy, array $files): void
    {
        $result = $client->send($message->mailbox, new OutgoingMail(
            to: $message->to_address,
            subject: $message->subject,
            text: (string) $message->body_text,
            html: $message->body_html,
            fromName: $this->fromName,
            messageId: $message->message_id,
            cc: OutgoingMail::split((string) $message->cc_address),
            inReplyTo: $message->in_reply_to,
            references: array_values(array_filter(explode(' ', (string) $message->references))),
            attachments: $files,
        ));

        if (! $result->ok) {
            $message->forceFill(['status' => MailMessage::STATUS_ERROR, 'error' => $result->error])->save();

            return;
        }
        $copyError = $sentCopy->store($message->mailbox, $result);
        $message->forceFill(['status' => MailMessage::STATUS_SENT, 'sent_at' => now(),
            'error' => $copyError ? "Enviada. A cópia em Enviados falhou: {$copyError}" : null])->save();
    }
}
