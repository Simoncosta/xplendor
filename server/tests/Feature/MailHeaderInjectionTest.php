<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\SupportInvoiceAvailableMail;
use App\Models\Mailbox;
use App\Services\Mailbox\OutgoingMail;
use App\Services\Mailbox\SmtpMailboxClient;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Cabeçalhos de e-mail com texto escrito pelo utilizador (o assunto, o nome do remetente): uma
 * quebra de linha (\r\n) nunca acrescenta um cabeçalho. Verifica-se a mensagem construída, nunca
 * enviada: na caixa do utilizador (SmtpMailboxClient::build) e num e-mail do sistema (o transporte
 * "array" dos testes guarda a mensagem em memória).
 */
class MailHeaderInjectionTest extends TestCase
{
    private const INJECTED = "Olá\r\nBcc: espiao@exemplo.invalid\r\nX-Injetado: sim";

    /** Os cabeçalhos da mensagem (até à primeira linha vazia), um por linha, já desdobrados. */
    private function headerLines(string $raw): array
    {
        $head = explode("\r\n\r\n", $raw, 2)[0];

        return preg_split("/\r\n(?![ \t])/", $head) ?: [];
    }

    private function assertNoInjectedHeaders(string $raw): void
    {
        $names = array_map(fn ($l) => strtolower(strstr($l, ':', true) ?: ''), $this->headerLines($raw));
        $this->assertNotContains('bcc', $names);
        $this->assertNotContains('x-injetado', $names);
        $this->assertSame(1, count(array_keys($names, 'subject', true)));
    }

    public function test_the_mailbox_message_keeps_the_subject_and_the_sender_name_on_one_line(): void
    {
        $mailbox = new Mailbox(['email' => 'caixa@exemplo.invalid']);
        [$email] = (new SmtpMailboxClient())->build($mailbox, new OutgoingMail(
            to: 'cliente@exemplo.invalid', subject: self::INJECTED, text: 'Corpo.', fromName: "Ana Vendas\r\nBcc: espiao@exemplo.invalid",
            cc: ['copia@exemplo.invalid'],
        ));

        $this->assertInstanceOf(Email::class, $email);
        $this->assertSame([], $email->getBcc());
        $this->assertSame('Olá Bcc: espiao@exemplo.invalid X-Injetado: sim', $email->getSubject());
        $this->assertSame('Ana Vendas Bcc: espiao@exemplo.invalid', $email->getFrom()[0]->getName());
        $this->assertNoInjectedHeaders($email->toString());
    }

    public function test_symfony_alone_never_turns_a_line_break_into_a_header(): void
    {
        // Sem a limpeza da XPLENDOR (outro sítio que um dia monte uma mensagem): o Symfony codifica a quebra.
        $email = (new Email())->from(new \Symfony\Component\Mime\Address('caixa@exemplo.invalid', "Ana\r\nBcc: espiao@exemplo.invalid"))
            ->to('cliente@exemplo.invalid')->subject(self::INJECTED)->text('Corpo.');
        $this->assertNoInjectedHeaders($email->toString());
    }

    public function test_a_system_mail_with_a_user_title_in_the_subject(): void
    {
        config(['mail.default' => 'array']);
        Mail::to('cliente@exemplo.invalid')->send(new SupportInvoiceAvailableMail(7, self::INJECTED, 'https://exemplo.invalid/app/support/7'));

        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $this->assertNoInjectedHeaders($messages[0]->toString());
        $this->assertSame([], $messages[0]->getOriginalMessage()->getBcc());
    }
}
