<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * XPLENDOR — Uma escrita de fornecedor no PingWin (criar | editar | anular). Alvo do
 * polling da UI enquanto o worker executa. status: pendente | ok | erro | duplicado.
 */
class PingwinSupplierWrite extends Model
{
    public const PENDING   = 'pendente';
    public const OK        = 'ok';
    public const ERROR     = 'erro';
    public const DUPLICATE = 'duplicado';

    protected $fillable = [
        'company_id', 'user_id', 'action', 'supplier_id', 'pingwin_id', 'fields',
        'allow_duplicate_nif', 'status', 'error_message', 'result', 'finished_at',
    ];

    protected $casts = [
        'fields'              => 'array',
        'result'              => 'array',
        'allow_duplicate_nif' => 'boolean',
        'finished_at'         => 'datetime',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(PingwinSupplier::class, 'supplier_id');
    }
}
