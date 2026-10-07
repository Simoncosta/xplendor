<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Pedido de "Nova empresa gerida": aviso ao root (novo) e à agência (aprovado ou recusado). Via queue; só escalares. */
class ManagedCompanyRequestMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $kind, // new | approved | declined
        public string $agencyName,
        public string $companyName,
        public ?string $note,
        public string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: match ($this->kind) {
            'approved' => "Empresa gerida aprovada: {$this->companyName}",
            'declined' => "Pedido de empresa gerida recusado: {$this->companyName}",
            default => "Pedido de nova empresa gerida: {$this->companyName} ({$this->agencyName})",
        });
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.agency.managed_company_request', with: [
            'kind' => $this->kind, 'agencyName' => $this->agencyName, 'companyName' => $this->companyName, 'note' => $this->note, 'url' => $this->url,
        ]);
    }
}
