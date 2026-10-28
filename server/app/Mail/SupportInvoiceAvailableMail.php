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
 * Avisa quem vê a faturação da XPLENDOR na empresa (faturacao_xplendor.ver) que há uma fatura nova
 * num pedido de "Alteração ao site", com o link para o pedido. Sem o PDF em anexo (abre-se no
 * pedido, por endereço assinado). Via QUEUE, fail-safe. Recebe escalares (não o model).
 */
class SupportInvoiceAvailableMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public int $ticketId,
        public string $ticketTitle,
        public string $ticketUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Fatura disponível: {$this->ticketTitle}");
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.support.invoice_available',
            with: ['ticketId' => $this->ticketId, 'ticketTitle' => $this->ticketTitle, 'ticketUrl' => $this->ticketUrl],
        );
    }
}
