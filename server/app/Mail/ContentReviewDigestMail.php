<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Resumo dos links de aprovação para quem produz (a cada 15 minutos, só quando há
 * novidades): aberturas, decisões, comentários e lembretes. Enviado pelo próprio job.
 *
 * @property array<int, array{company: string, title: string, message: string, severity: string}> $lines
 */
class ContentReviewDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public array $lines) {}

    public function envelope(): Envelope
    {
        $n = count($this->lines);
        $urgent = collect($this->lines)->contains(fn ($l) => $l['severity'] === 'high');

        return new Envelope(subject: ($urgent ? 'Urgente: ' : '') . "Linha Editorial: {$n} " . ($n === 1 ? 'novidade' : 'novidades'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.content-review.digest', with: ['lines' => $this->lines]);
    }
}
