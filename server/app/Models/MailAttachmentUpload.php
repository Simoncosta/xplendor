<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * E-mail 5b · Um anexo carregado para uma mensagem a enviar (temporário e privado). Antes do envio
 * tem mail_message_id a null; depois do envio, o ficheiro e a linha apagam-se.
 */
class MailAttachmentUpload extends Model
{
    protected $fillable = [
        'uuid', 'company_id', 'user_id', 'mailbox_id', 'mail_message_id', 'name', 'mime', 'size', 'disk', 'path',
        'source', 'source_file_id', 'expires_at',
    ];

    protected $casts = [
        'size' => 'integer',
        'expires_at' => 'datetime',
    ];

    /** O que o ecrã recebe (o uuid é o identificador; o caminho nunca sai). */
    public function toApiArray(): array
    {
        return ['id' => $this->uuid, 'name' => $this->name, 'size' => $this->size, 'mime' => $this->mime, 'source' => $this->source];
    }
}
