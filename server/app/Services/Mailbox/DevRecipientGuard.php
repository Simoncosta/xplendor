<?php

declare(strict_types=1);

namespace App\Services\Mailbox;

use App\Models\Mailbox;

/**
 * E-mail 3b · A proteção do dev: fora de produção, todas as mensagens enviadas pelas caixas vão só
 * para o endereço da própria caixa, nunca para clientes nem para outros endereços, com o
 * destinatário original no assunto ("[dev, para: …]"). Envolve o cliente de envio escolhido pelo
 * driver (AppServiceProvider), por isso vale para todos os caminhos (o pós-venda, o teste e o que vier).
 */
class DevRecipientGuard implements MailboxClient
{
    public function __construct(private readonly MailboxClient $inner) {}

    public static function active(): bool
    {
        return ! app()->environment('production');
    }

    public function send(Mailbox $mailbox, OutgoingMail $mail): MailboxSendResult
    {
        return $this->inner->send($mailbox, self::active() ? self::redirect($mailbox, $mail) : $mail);
    }

    public static function redirect(Mailbox $mailbox, OutgoingMail $mail): OutgoingMail
    {
        $to = $mail->toList();
        if (! $mail->cc && count($to) === 1 && strcasecmp($to[0], trim($mailbox->email)) === 0) {
            return $mail; // já é só para a própria caixa (o "Testar envio")
        }
        $who = implode(', ', $to) . ($mail->cc ? '; cc: ' . implode(', ', $mail->cc) : '');

        return new OutgoingMail(
            to: $mailbox->email,
            subject: "[dev, para: {$who}] {$mail->subject}",
            text: $mail->text,
            html: $mail->html,
            fromName: $mail->fromName,
            messageId: $mail->messageId,
            cc: [], // o Cc também nunca sai do dev
            inReplyTo: $mail->inReplyTo,
            references: $mail->references,
        );
    }
}
