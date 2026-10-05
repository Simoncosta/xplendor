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
 * Blog — avisa um administrador da empresa de que há um artigo em revisão. Via QUEUE;
 * recebe escalares (não o model) para evitar problemas de serialização no worker.
 */
class BlogReviewRequestedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public int $blogId,
        public string $title,
        public string $companyName,
        public string $authorName,
        public string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Artigo para rever: {$this->title}");
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.blogs.review-requested',
            with: [
                'title'       => $this->title,
                'companyName' => $this->companyName,
                'authorName'  => $this->authorName,
                'url'         => $this->url,
            ],
        );
    }
}
