<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Uma fatura retirada (ou trocada) de um pedido de suporte: o ficheiro fica no disco privado, já
 * sem endereço assinado, até o comando support:purge-removed-files o apagar (30 dias, por omissão).
 * Até lá conta no espaço da empresa.
 */
class SupportTicketRemovedFile extends Model
{
    public const KIND_INVOICE = 'invoice';

    protected $fillable = ['company_id', 'support_ticket_id', 'kind', 'path', 'size_bytes', 'removed_by_user_id', 'removed_at', 'purged_at'];

    protected $casts = [
        'removed_at' => 'datetime',
        'purged_at'  => 'datetime',
    ];
}
