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
 * XPLENDOR — Avisa a equipa de que a empresa ligada aceitou ou recusou um
 * orçamento. Via queue, fail-safe. Escalares apenas.
 */
class QuoteDecisionMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public int $quoteId,
        public string $number,
        public string $companyName,
        public string $title,
        public bool $accepted,
        public float $totalMonthly,
        public float $totalOneOff,
    ) {}

    public function envelope(): Envelope
    {
        $verb = $this->accepted ? 'aceite' : 'recusado';

        return new Envelope(subject: "Orçamento {$this->number} {$verb} por {$this->companyName}");
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.quotes.decision',
            with: [
                'number'       => $this->number,
                'companyName'  => $this->companyName,
                'title'        => $this->title,
                'accepted'     => $this->accepted,
                'totalMonthly' => $this->totalMonthly,
                'totalOneOff'  => $this->totalOneOff,
            ],
        );
    }
}
