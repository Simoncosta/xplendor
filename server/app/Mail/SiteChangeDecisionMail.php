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
 * DMS — Avisa o SUPER-ADMIN (Simon) da decisão do stand sobre o orçamento de
 * "Alteração ao site": APROVADO (para faturar) ou REJEITADO (ticket fechou).
 * Via QUEUE, fail-safe. Escalares apenas.
 */
class SiteChangeDecisionMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public int $ticketId,
        public string $ticketTitle,
        public string $companyName,
        public string $authorName,
        public bool $approved,
        public float $quotedAmount,
    ) {}

    public function envelope(): Envelope
    {
        $verb = $this->approved ? 'aprovado' : 'rejeitado';

        return new Envelope(
            subject: "Orçamento {$verb} — {$this->companyName} — {$this->ticketTitle}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.support.site_change_decision',
            with: [
                'ticketId'     => $this->ticketId,
                'ticketTitle'  => $this->ticketTitle,
                'companyName'  => $this->companyName,
                'authorName'   => $this->authorName,
                'approved'     => $this->approved,
                'quotedAmount' => $this->quotedAmount,
            ],
        );
    }
}
