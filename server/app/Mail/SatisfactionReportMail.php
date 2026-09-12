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
 * DMS Pós-venda (Incremento 5) — email COM O LINK do relatório, enviado ao
 * CLIENTE (comprador). Vai por QUEUE (ShouldQueue) para não travar o painel.
 */
class SatisfactionReportMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public ?string $customerName,
        public string $companyName,
        public string $message,
        public string $link,
        public ?string $logoUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'A sua nova viatura — ' . $this->companyName,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.satisfaction.report',
            with: [
                'customerName' => $this->customerName,
                'companyName'  => $this->companyName,
                'message'      => $this->message,
                'link'         => $this->link,
                'logoUrl'      => $this->logoUrl,
            ],
        );
    }
}
