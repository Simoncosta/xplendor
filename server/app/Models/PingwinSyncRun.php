<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * XPLENDOR — Registo de que dias foram sincronizados (por empresa). Distingue
 * "0€ real" de "ainda não sincronizado" — base do portão de honestidade das
 * comparações (só mostrar % se o período anterior estiver TOTALMENTE sincronizado).
 */
class PingwinSyncRun extends Model
{
    protected $fillable = [
        'company_id', 'business_date', 'status', 'locations_count', 'synced_at',
    ];

    protected $casts = [
        // business_date como string 'Y-m-d' (sem cast) — UPSERT idempotente por dia.
        'locations_count' => 'integer',
        'synced_at'       => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
