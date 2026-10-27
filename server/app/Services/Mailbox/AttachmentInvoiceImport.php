<?php

declare(strict_types=1);

namespace App\Services\Mailbox;

use App\Models\Mailbox;
use App\Services\OcrInvoiceIntake;

/**
 * E-mail 7 · "Importar para as Faturas": o anexo (PDF ou imagem) é lido ao vivo da caixa, passa
 * primeiro pelo ClamAV e depois entra na mesma entrada do carregamento manual (OcrInvoiceIntake: o
 * duplicado pelo conteúdo, o teto, a leitura na fila com o QR primeiro, depois a IA e o fornecedor
 * pelo NIF). É o único caso em que um anexo fica guardado: nas Faturas, privado. A fatura guarda a
 * caixa e o Message-ID, nunca o conteúdo do e-mail.
 */
class AttachmentInvoiceImport
{
    public function __construct(private readonly MailboxInbox $inbox, private readonly OcrInvoiceIntake $intake) {}

    /** @return array{status: 'created'|'duplicate'|'cap'|'refused', invoice?: \App\Models\OcrInvoice, cap?: int, error?: string, code?: int} */
    public function import(Mailbox $mailbox, string $folder, int $uid, int $index): array
    {
        $result = $this->inbox->attachment($mailbox, $folder, $uid, $index);
        if (! $result['ok']) {
            return ['status' => 'refused', 'error' => $result['error'], 'code' => 422];
        }
        $file = $result['file'];
        $mime = strtolower($file['mime']);
        if (! isset(OcrInvoiceIntake::MIMES[$mime])) {
            return ['status' => 'refused', 'error' => 'Só se importam faturas em PDF ou imagem (JPG, PNG ou WEBP).', 'code' => 422];
        }
        if (strlen($file['content']) > OcrInvoiceIntake::MAX_BYTES) {
            return ['status' => 'refused', 'error' => 'O anexo tem mais de 12 MB e não pode ser importado.', 'code' => 422];
        }

        // O antivírus corre primeiro, dentro da entrada (a mesma do carregamento manual).
        return $this->intake->accept($mailbox->company_id, $file['content'], $mime, [
            'origin' => 'email', 'origin_mailbox_id' => $mailbox->id, 'origin_mailbox_email' => $mailbox->email, 'origin_message_id' => $result['message_id'],
        ], $file['name']);
    }
}
