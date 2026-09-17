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
 * XPLENDOR — Avisa o SUPER-ADMIN (Simon) da decisão da empresa sobre um
 * orçamento avulso ligado a ela: APROVADO (para faturar) ou REJEITADO.
 * Via QUEUE, fail-safe. Escalares apenas.
 */
class QuoteDecisionMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public int $quoteId,
        public string $companyName,
        public string $description,
        public bool $approved,
        public float $amount,
    ) {}

    public function envelope(): Envelope
    {
        $verb = $this->approved ? 'aprovado' : 'rejeitado';

        return new Envelope(subject: "Orçamento {$verb} — {$this->companyName}");
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.quotes.decision',
            with: [
                'quoteId'     => $this->quoteId,
                'companyName' => $this->companyName,
                'description' => $this->description,
                'approved'    => $this->approved,
                'amount'      => $this->amount,
            ],
        );
    }
}
