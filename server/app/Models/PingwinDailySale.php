<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * XPLENDOR — Vendas PingWin por loja/dia. Dinheiro em CÊNTIMOS inteiros (nunca
 * float). UNIQUE (location_id, business_date) → UPSERT idempotente no re-sync.
 */
class PingwinDailySale extends Model
{
    protected $fillable = [
        'company_id', 'location_id', 'business_date',
        'gross_cents', 'credit_notes_cents', 'discounts_cents', 'net_cents',
        'tax_cents', 'invoiced_cents', 'tickets_count', 'covers_count', 'synced_at',
    ];

    protected $casts = [
        // business_date fica como string 'Y-m-d' (sem cast date) — para o UPSERT
        // por (location_id, business_date) casar exatamente e não duplicar.
        'gross_cents'        => 'integer',
        'credit_notes_cents' => 'integer',
        'discounts_cents'    => 'integer',
        'net_cents'          => 'integer',
        'tax_cents'          => 'integer',
        'invoiced_cents'     => 'integer',
        'tickets_count'      => 'integer',
        'covers_count'       => 'integer',
        'synced_at'          => 'datetime',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(PingwinLocation::class, 'location_id');
    }
}
