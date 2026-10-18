<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Site do consultor: o aviso imediato de um contacto novo. Via QUEUE. NUNCA leva os dados
 * pessoais do visitante (nome, email, telefone, mensagem): só o tipo, a zona ou a referência e a
 * ligação para a app, onde o contacto fica atrás da ACL.
 */
class SiteContactReceivedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public int $contactId,
        public string $summary,
        public string $companyName,
        public string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Novo contacto do site: {$this->summary}");
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.consultant-site.contact-received',
            with: ['summary' => $this->summary, 'companyName' => $this->companyName, 'url' => $this->url],
        );
    }
}
