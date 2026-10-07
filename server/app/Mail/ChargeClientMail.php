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
 * Cobrança da XPLENDOR para o cliente: nova fatura, lembrete, pagamento confirmado ou
 * pagamento não confirmado. O remetente é o da configuração de email da plataforma
 * (MAIL_FROM_ADDRESS e MAIL_FROM_NAME). Via queue; só escalares.
 */
class ChargeClientMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public const KINDS = ['new', 'reminder', 'paid', 'refused'];

    public function __construct(
        public string $kind,
        public string $companyName,
        public string $description,
        public float $amount,
        public string $dueDate,
        public bool $overdue,
        public string $url,
        public ?string $note = null,
    ) {}

    public function envelope(): Envelope
    {
        $subject = match ($this->kind) {
            'new' => 'Nova fatura da XPLENDOR',
            'paid' => 'Pagamento confirmado pela XPLENDOR',
            'refused' => 'Pagamento ainda não confirmado pela XPLENDOR',
            default => $this->overdue ? 'Lembrete: fatura da XPLENDOR vencida' : 'Lembrete: fatura da XPLENDOR vence hoje',
        };

        return new Envelope(subject: $subject . ' (' . number_format($this->amount, 2, ',', '.') . ' €)');
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.charges.client', with: [
            'kind' => $this->kind, 'companyName' => $this->companyName, 'description' => $this->description,
            'amount' => $this->amount, 'dueDate' => $this->dueDate, 'overdue' => $this->overdue, 'url' => $this->url, 'note' => $this->note,
        ]);
    }
}
