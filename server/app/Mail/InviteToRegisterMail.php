<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InviteToRegisterMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $companyName;
    public string $inviteUrl;

    public function __construct(string $companyName, string $inviteUrl)
    {
        $this->companyName = $companyName;
        $this->inviteUrl = $inviteUrl;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Convite para aceder à {$this->companyName} no " . config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.invites.register',
            with: [
                'companyName' => $this->companyName,
                'inviteUrl'   => $this->inviteUrl,
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
