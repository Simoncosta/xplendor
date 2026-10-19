<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * XPLENDOR — Um "Fechar no PingWin" (8002) de um documento de fornecedor em Aberto: quem, quando,
 * o documento, o estado antes e depois (relido) e o resultado. Um lote fecha um de cada vez e para
 * no primeiro erro (os seguintes ficam "nao_feito").
 */
class SupplierDocumentClose extends Model
{
    public const PENDING = 'pendente';
    public const OK = 'ok';
    public const ERROR = 'erro';
    public const CONFIRM_ERROR = 'erro_confirmacao';
    public const NOT_DONE = 'nao_feito';

    protected $fillable = [
        'company_id', 'batch', 'position', 'docheader_id', 'docconfig_id', 'document', 'entity_name', 'total_cents',
        'user_id', 'status', 'status_before', 'status_after', 'error', 'result', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'position' => 'integer',
        'total_cents' => 'integer',
        'result' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
