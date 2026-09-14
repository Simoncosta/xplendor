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
 * DMS — Avisa o STAND que há um orçamento novo para aprovar (ticket pago
 * "Alteração ao site"). Via QUEUE, fail-safe (nunca bloqueia a operação).
 * Recebe escalares (não o model) para serialização segura no worker.
 */
class SiteChangeQuotedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public int $ticketId,
        public string $ticketTitle,
        public float $estimatedHours,
        public float $quotedAmount,
        public float $hourlyRate,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Orçamento para aprovar — {$this->ticketTitle}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.support.site_change_quoted',
            with: [
                'ticketId'       => $this->ticketId,
                'ticketTitle'    => $this->ticketTitle,
                'estimatedHours' => $this->estimatedHours,
                'quotedAmount'   => $this->quotedAmount,
                'hourlyRate'     => $this->hourlyRate,
            ],
        );
    }
}
