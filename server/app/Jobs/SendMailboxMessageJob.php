<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MailMessage;
use App\Services\Mailbox\MailboxClient;
use App\Services\Mailbox\OutgoingMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * E-mail 2 · Envia uma mensagem gravada (MailMessage) pela caixa de correio de quem a escreveu. Na
 * fila só vai o id: as credenciais decifram-se aqui, no worker (o Mail do Laravel não serve, porque
 * o transporte de cada caixa não sobrevive à fila). Uma só tentativa: repetir um envio SMTP pode
 * mandar a mensagem duas vezes; o erro fica na mensagem, com o motivo.
 */
class SendMailboxMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly int $messageId, public readonly string $fromName = '') {}

    public function handle(MailboxClient $client): void
    {
        $message = MailMessage::with('mailbox')->find($this->messageId);
        if (! $message || $message->status !== MailMessage::STATUS_QUEUED) {
            return; // já tratada (ou apagada)
        }
        if (! $message->mailbox) {
            $message->forceFill(['status' => MailMessage::STATUS_ERROR, 'error' => 'A caixa de correio foi removida antes do envio.'])->save();

            return;
        }

        $result = $client->send($message->mailbox, new OutgoingMail(
            to: $message->to_address,
            subject: $message->subject,
            text: (string) $message->body_text,
            html: $message->body_html,
            fromName: $this->fromName,
            messageId: $message->message_id,
        ));

        $message->forceFill($result->ok
            ? ['status' => MailMessage::STATUS_SENT, 'sent_at' => now(), 'error' => null]
            : ['status' => MailMessage::STATUS_ERROR, 'error' => $result->error])->save();
    }
}
