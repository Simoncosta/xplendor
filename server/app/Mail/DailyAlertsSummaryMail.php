<?php

namespace App\Mail;

use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class DailyAlertsSummaryMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Company $company,
        public Collection $alerts,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('⚠️ %d alertas nas campanhas hoje', $this->alerts->count()),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.alerts.daily-summary',
            with: [
                'companyName' => $this->company->trade_name ?: $this->company->fiscal_name,
                'alerts' => $this->alerts,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
