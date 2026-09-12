<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * DMS — Notifica o super-admin quando um stand ABRE um ticket. Via QUEUE (não
 * bloqueia a criação do ticket). Recebe escalares (não o model) para evitar
 * problemas de serialização/lazy-load no worker.
 */
class SupportTicketCreatedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    private const TYPE_LABELS = [
        'idea' => 'Ideia', 'improvement' => 'Melhoria', 'bug' => 'Bug', 'suggestion' => 'Sugestão',
    ];

    public function __construct(
        public int $ticketId,
        public string $companyName,
        public string $authorName,
        public string $type,
        public string $title,
        public string $description,
        public bool $hasScreenshot,
    ) {}

    public function envelope(): Envelope
    {
        $typeLabel = self::TYPE_LABELS[$this->type] ?? $this->type;

        return new Envelope(
            subject: "Novo ticket de suporte — {$typeLabel} — {$this->companyName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.support.created',
            with: [
                'ticketId'      => $this->ticketId,
                'companyName'   => $this->companyName,
                'authorName'    => $this->authorName,
                'typeLabel'     => self::TYPE_LABELS[$this->type] ?? $this->type,
                'title'         => $this->title,
                'description'   => $this->description,
                'hasScreenshot' => $this->hasScreenshot,
            ],
        );
    }
}
