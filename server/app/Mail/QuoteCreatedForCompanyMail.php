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
 * XPLENDOR — Avisa a EMPRESA ligada de que tem um orçamento para decidir no painel
 * dela. Só é enviado quando o orçamento é ENVIADO (nunca ao criar ou editar um
 * rascunho). Via queue, fail-safe. Escalares apenas.
 */
class QuoteCreatedForCompanyMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public int $quoteId,
        public string $number,
        public string $title,
        public float $totalMonthly,
        public float $totalOneOff,
        public string $validUntil,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Orçamento {$this->number} da XPLENDOR para decidir");
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.quotes.created_for_company',
            with: [
                'number'       => $this->number,
                'title'        => $this->title,
                'totalMonthly' => $this->totalMonthly,
                'totalOneOff'  => $this->totalOneOff,
                'validUntil'   => $this->validUntil,
            ],
        );
    }
}
