<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * XPLENDOR — Saldo de conta corrente de um fornecedor (ccbalance do PingWin) e estado
 * da sync. UNIQUE (company_id, supplier_id).
 */
class PingwinSupplierCcBalance extends Model
{
    public const STATUS_QUEUED  = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_OK      = 'ok';
    public const STATUS_FAILED  = 'failed';

    protected $fillable = [
        'company_id', 'supplier_id', 'entity_pingwin_id', 'balance_cents',
        'reconciled', 'sync_status', 'last_error', 'synced_at',
    ];

    protected $casts = [
        'balance_cents' => 'integer',
        'reconciled'    => 'boolean',
        'synced_at'     => 'datetime',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(PingwinSupplier::class, 'supplier_id');
    }
}
