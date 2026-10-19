<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * XPLENDOR — Finanças: os documentos do mês para o contabilista (a folha "Documentos enviados para a
 * contabilidade" em XLSX e os PDFs das faturas que estão na XPLENDOR). Enviado só com o clique, depois
 * da pré-visualização; não vai pela fila (os anexos são gerados no momento).
 */
class AccountantDocumentsMail extends Mailable
{
    /**
     * @param  list<string>  $lines
     * @param  list<array{number:string, supplier:string, date:string, value:string}>  $documents
     * @param  list<array{name:string, data:string, mime:string}>  $files
     */
    public function __construct(
        public string $subjectLine,
        public string $title,
        public array $lines,
        public array $documents,
        public string $closing,
        public array $files,
        public ?string $replyToAddress = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine, replyTo: $this->replyToAddress ? [$this->replyToAddress] : []);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.finance.accountant', with: [
            'title' => $this->title, 'lines' => $this->lines, 'documents' => $this->documents, 'closing' => $this->closing,
        ]);
    }

    public function attachments(): array
    {
        return array_map(fn ($f) => Attachment::fromData(fn () => $f['data'], $f['name'])->withMime($f['mime']), $this->files);
    }
}
