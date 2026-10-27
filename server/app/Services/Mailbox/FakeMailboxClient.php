<?php

declare(strict_types=1);

namespace App\Services\Mailbox;

use App\Models\Mailbox;
use Illuminate\Support\Str;

/**
 * O cliente simulado (driver fake, nos testes): guarda as mensagens em memória e nunca liga a
 * nenhum servidor. failWith simula um erro do servidor (já com o texto que a pessoa vê).
 */
class FakeMailboxClient implements MailboxClient
{
    /** @var list<array{mailbox_id: int, from: string, from_name: ?string, to: string, cc: list<string>, subject: string, text: string, html: ?string, message_id: string, in_reply_to: ?string, references: list<string>}> */
    public static array $sent = [];

    public static ?string $failWith = null;

    public static function reset(): void
    {
        self::$sent = [];
        self::$failWith = null;
    }

    public function send(Mailbox $mailbox, OutgoingMail $mail): MailboxSendResult
    {
        if (self::$failWith !== null) {
            return MailboxSendResult::failed(self::$failWith);
        }
        $messageId = $mail->messageId ?? '<' . Str::uuid() . '@xplendor.invalid>';
        self::$sent[] = ['mailbox_id' => $mailbox->id, 'from' => $mailbox->email, 'from_name' => $mail->fromName, 'to' => $mail->to, 'cc' => $mail->cc,
            'subject' => $mail->subject, 'text' => $mail->text, 'html' => $mail->html, 'message_id' => $messageId, 'in_reply_to' => $mail->inReplyTo,
            'references' => $mail->references];

        $raw = "Message-ID: {$messageId}\r\nFrom: {$mailbox->email}\r\nTo: {$mail->to}\r\nSubject: {$mail->subject}\r\n\r\n{$mail->text}";

        return MailboxSendResult::sent($messageId, $raw);
    }
}
