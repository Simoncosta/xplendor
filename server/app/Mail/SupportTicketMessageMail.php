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
 * DMS — Notifica o super-admin quando um STAND escreve uma mensagem na thread
 * (nunca para mensagens do próprio staff). Via QUEUE, escalares.
 */
class SupportTicketMessageMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public int $ticketId,
        public string $ticketTitle,
        public string $companyName,
        public string $authorName,
        public string $body,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Nova resposta no ticket #{$this->ticketId} — {$this->companyName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.support.message',
            with: [
                'ticketId'    => $this->ticketId,
                'ticketTitle' => $this->ticketTitle,
                'companyName' => $this->companyName,
                'authorName'  => $this->authorName,
                'body'        => $this->body,
            ],
        );
    }
}
