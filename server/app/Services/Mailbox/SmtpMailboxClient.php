<?php

declare(strict_types=1);

namespace App\Services\Mailbox;

use App\Models\Mailbox;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * O envio real por SMTP, com o servidor, a porta e a palavra-passe da caixa (o transporte é montado
 * a cada envio: o Mail do Laravel só conhece o servidor da plataforma). Fora de produção, sem
 * mailboxes.real_send, a mensagem vai para o Mailpit com o mesmo remetente.
 *
 * No envio real liga ao endereço público para onde o nome resolve (MailboxHosts) e verifica o
 * certificado pelo nome: uma caixa não serve para chegar a serviços internos.
 */
class SmtpMailboxClient implements MailboxClient
{
    public function send(Mailbox $mailbox, OutgoingMail $mail): MailboxSendResult
    {
        $real = app()->environment('production') || (bool) config('mailboxes.real_send');
        try {
            $transport = $real ? $this->realTransport($mailbox) : $this->devTransport();
            if ($transport === null) {
                return MailboxSendResult::failed("O servidor {$mailbox->smtp_host} não foi encontrado ou não é um servidor de e-mail público.");
            }
            $email = (new Email())
                ->from(new Address($mailbox->email, (string) $mail->fromName))
                ->to($mail->to)
                ->subject($mail->subject)
                ->text($mail->text);
            if ($mail->html !== null) {
                $email->html($mail->html);
            }
            // O Message-ID é sempre o nosso (o SentMessage::getMessageId() pode ser o "queued as" do servidor).
            $messageId = trim($mail->messageId ?? (Str::uuid() . '@' . (Str::after($mailbox->email, '@') ?: 'xplendor.pt')), '<>');
            $email->getHeaders()->addIdHeader('Message-ID', $messageId);
            $transport->send($email);

            return MailboxSendResult::sent('<' . $messageId . '>', $real);
        } catch (TransportExceptionInterface $e) {
            Log::info('[Caixa de correio] Envio falhou', ['mailbox_id' => $mailbox->id, 'code' => $e->getCode()]);

            return MailboxSendResult::failed($this->describe($e, $mailbox, $real));
        }
    }

    private function realTransport(Mailbox $mailbox): ?EsmtpTransport
    {
        $ip = MailboxHosts::publicAddress($mailbox->smtp_host);
        if ($ip === null) {
            return null;
        }
        $transport = new EsmtpTransport($ip, $mailbox->smtp_port, $mailbox->smtp_encryption === 'ssl');
        if ($mailbox->smtp_encryption === 'starttls') {
            $transport->setRequireTls(true);
        }
        $stream = $transport->getStream();
        if ($stream instanceof SocketStream) {
            $stream->setTimeout((float) config('mailboxes.timeout'));
            $stream->setStreamOptions(['ssl' => ['peer_name' => strtolower($mailbox->smtp_host), 'verify_peer' => true, 'verify_peer_name' => true]]);
        }
        $transport->setUsername($mailbox->username);
        $transport->setPassword((string) $mailbox->password);

        return $transport;
    }

    private function devTransport(): EsmtpTransport
    {
        $transport = new EsmtpTransport((string) config('mailboxes.dev_smtp.host'), (int) config('mailboxes.dev_smtp.port'), false);
        $transport->setAutoTls(false);
        $stream = $transport->getStream();
        if ($stream instanceof SocketStream) {
            $stream->setTimeout((float) config('mailboxes.timeout'));
        }

        return $transport;
    }

    /** O motivo em português, sem a palavra-passe nem o texto cru do servidor. */
    private function describe(TransportExceptionInterface $e, Mailbox $mailbox, bool $real): string
    {
        $message = strtolower($e->getMessage());
        $where = $real ? "{$mailbox->smtp_host}:{$mailbox->smtp_port}" : 'o Mailpit (dev)';

        return match (true) {
            str_contains($message, 'authenticate') || str_contains($message, '535') || str_contains($message, '534')
                => 'O servidor recusou o utilizador ou a palavra-passe.' . ($mailbox->account_type === 'gmail' ? ' No Gmail, use uma palavra-passe de app.' : ''),
            str_contains($message, 'ssl') || str_contains($message, 'tls') || str_contains($message, 'certificate') || str_contains($message, 'crypto')
                => "Falhou a ligação segura (SSL/TLS) a {$where}. Confirme a porta e o tipo de segurança.",
            str_contains($message, 'timed out') || str_contains($message, 'timeout')
                => "O servidor {$where} não respondeu a tempo.",
            str_contains($message, 'connection could not be established') || str_contains($message, 'refused') || str_contains($message, 'unable to connect')
                => "Não foi possível ligar a {$where}.",
            str_contains($message, '550') || str_contains($message, '553') || str_contains($message, '554')
                => 'O servidor recusou a mensagem (o remetente tem de ser o endereço da própria caixa).',
            default => 'O servidor de e-mail não aceitou o envio.',
        };
    }
}
