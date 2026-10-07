<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Aviso à equipa XPLENDOR: um cliente indicou que pagou uma cobrança. Via queue; só escalares. */
class ChargeTeamMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $companyName,
        public string $description,
        public float $amount,
        public string $dueDate,
        public string $via,
        public bool $hasProof,
        public ?string $note,
        public string $adminUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Pagamento indicado: {$this->companyName} (" . number_format($this->amount, 2, ',', '.') . ' €)');
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.charges.team', with: [
            'companyName' => $this->companyName, 'description' => $this->description, 'amount' => $this->amount, 'dueDate' => $this->dueDate,
            'via' => $this->via, 'hasProof' => $this->hasProof, 'note' => $this->note, 'adminUrl' => $this->adminUrl,
        ]);
    }
}
