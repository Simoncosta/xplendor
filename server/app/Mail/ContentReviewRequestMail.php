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
 * Link de aprovação de conteúdos enviado ao cliente (no envio, no reenvio e no lembrete).
 * Via queue; só escalares. O token vai no fragmento do URL (#).
 */
class ContentReviewRequestMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public ?string $recipientName,
        public string $companyName,
        public string $title,
        public int $pendingCount,
        public string $url,
        public string $expiresOn,
        public bool $reminder = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: ($this->reminder ? 'Lembrete: publicações à espera de aprovação' : 'Publicações para aprovar') . " ({$this->title})");
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.content-review.request', with: [
            'recipientName' => $this->recipientName, 'companyName' => $this->companyName, 'title' => $this->title,
            'pendingCount' => $this->pendingCount, 'url' => $this->url, 'expiresOn' => $this->expiresOn, 'reminder' => $this->reminder,
        ]);
    }
}
