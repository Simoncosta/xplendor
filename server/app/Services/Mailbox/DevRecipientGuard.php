<?php

declare(strict_types=1);

namespace App\Services\Mailbox;

use App\Models\Mailbox;

/**
 * E-mail 3b · A proteção do dev: fora de produção, todas as mensagens enviadas pelas caixas vão só
 * para o endereço da própria caixa, nunca para clientes nem para outros endereços, com o
 * destinatário original no assunto ("[dev, para: …]"). Envolve o cliente de envio escolhido pelo
 * driver (AppServiceProvider), por isso vale para todos os caminhos (o pós-venda, o teste e o que vier).
 * E-mail 5: os "Endereços de teste (só dev)" da caixa saem mesmo para eles (no Para e no Cc); os
 * restantes continuam desviados para a própria caixa, e só esses ficam no assunto.
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
        // Pode sair: a própria caixa e os endereços de teste dela.
        $allowed = [strtolower(trim($mailbox->email)), ...$mailbox->devTestAddresses()];
        $ok = fn (string $address) => in_array(strtolower($address), $allowed, true);
        [$to, $cc] = [$mail->toList(), $mail->cc];
        $heldTo = array_values(array_filter($to, fn ($a) => ! $ok($a)));
        $heldCc = array_values(array_filter($cc, fn ($a) => ! $ok($a)));
        if (! $heldTo && ! $heldCc) {
            return $mail; // só para a própria caixa (o "Testar envio") ou para os endereços de teste
        }
        $who = implode(', ', $heldTo) . ($heldCc ? ($heldTo ? '; ' : '') . 'cc: ' . implode(', ', $heldCc) : '');
        $sendTo = array_values(array_unique([...array_filter($to, $ok), $mailbox->email]));

        return new OutgoingMail(
            to: implode(', ', $sendTo),
            subject: "[dev, para: {$who}] {$mail->subject}",
            text: $mail->text,
            html: $mail->html,
            fromName: $mail->fromName,
            messageId: $mail->messageId,
            cc: array_values(array_filter($cc, $ok)), // o Cc só sai para os endereços de teste
            inReplyTo: $mail->inReplyTo,
            references: $mail->references,
            attachments: $mail->attachments,
        );
    }
}
