<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * XPLENDOR — CoverManager: agregado de reservas por turno (RGPD: SÓ números,
 * nunca PII). UNIQUE (location_id, business_date, shift) → UPSERT idempotente.
 */
class CmReservationShiftSummary extends Model
{
    protected $table = 'cm_reservation_shift_summary';

    protected $fillable = [
        'company_id', 'location_id', 'business_date', 'shift',
        'guests_total', 'reservations_count', 'walk_ins_count', 'cancelled_count', 'synced_at',
    ];

    protected $casts = [
        // business_date fica como string 'Y-m-d' (sem cast) — UPSERT casa exato.
        'guests_total'       => 'integer',
        'reservations_count' => 'integer',
        'walk_ins_count'     => 'integer',
        'cancelled_count'    => 'integer',
        'synced_at'          => 'datetime',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(PingwinLocation::class, 'location_id');
    }
}
