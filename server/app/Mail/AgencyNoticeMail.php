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
 * Avisos da gestão por agências (pedido de gestão, resposta, fim da relação, arquivo,
 * situação de faturação para o root): um assunto, um título, parágrafos e um botão para a
 * app. Via queue; só escalares.
 */
class AgencyNoticeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param string[] $lines */
    public function __construct(
        public string $subjectLine,
        public string $title,
        public array $lines,
        public ?string $actionLabel = null,
        public ?string $url = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.agency.notice', with: [
            'title' => $this->title, 'lines' => $this->lines, 'actionLabel' => $this->actionLabel, 'url' => $this->url,
        ]);
    }
}
