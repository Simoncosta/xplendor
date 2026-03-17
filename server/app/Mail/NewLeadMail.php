<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewLeadMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $carName;
    public string $customerName;
    public string $customerPhone;
    public string $customerEmail;
    public ?string $message;

    public function __construct(
        string $carName,
        string $customerName,
        string $customerPhone,
        string $customerEmail,
        ?string $message = null
    ) {
        $this->carName = $carName;
        $this->customerName = $customerName;
        $this->customerPhone = $customerPhone;
        $this->customerEmail = $customerEmail;
        $this->message = $message;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "🚗 Novo lead para {$this->carName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.leads.new',
            with: [
                'carName'        => $this->carName,
                'customerName'   => $this->customerName,
                'customerPhone'  => $this->customerPhone,
                'customerEmail'  => $this->customerEmail,
                'message'        => $this->message,
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
