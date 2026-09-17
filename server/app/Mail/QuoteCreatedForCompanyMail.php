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
 * XPLENDOR — Avisa a EMPRESA (stand) que tem um orçamento novo para aprovar no
 * painel dela. Via QUEUE, fail-safe. Escalares apenas (serialização segura).
 */
class QuoteCreatedForCompanyMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public int $quoteId,
        public string $description,
        public float $amount,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Tens um orçamento novo para aprovar — XPLENDOR');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.quotes.created_for_company',
            with: [
                'quoteId'     => $this->quoteId,
                'description' => $this->description,
                'amount'      => $this->amount,
            ],
        );
    }
}
