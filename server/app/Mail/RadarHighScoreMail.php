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
 * Bússola 7: o aviso por e-mail de um sinal do Radar com nota alta (no máximo um por dia e por
 * empresa). Via queue; só escalares.
 */
class RadarHighScoreMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public ?string $recipientName,
        public string $companyName,
        public string $topic,
        public string $why,
        public string $validUntil,
        public string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Radar: está a subir {$this->topic}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.radar.high-score', with: [
            'recipientName' => $this->recipientName, 'companyName' => $this->companyName, 'topic' => $this->topic,
            'why' => $this->why, 'validUntil' => $this->validUntil, 'url' => $this->url,
        ]);
    }
}
