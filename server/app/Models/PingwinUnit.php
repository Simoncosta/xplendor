<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * XPLENDOR — Unidade do PingWin (base de conversão), só leitura. UNIQUE
 * (company_id, pingwin_id) → UPSERT idempotente. parent_pingwin_id + unit_value
 * descrevem a conversão (1 desta = unit_value da unidade-base).
 */
class PingwinUnit extends Model
{
    protected $fillable = [
        'company_id', 'pingwin_id', 'description', 'shortname', 'product_pingwin_id',
        'parent_pingwin_id', 'unit_value', 'purchase', 'sale', 'stock',
        'net_weight', 'external_measure', 'raw', 'is_active', 'synced_at',
    ];

    protected $casts = [
        'unit_value'  => 'float',
        'net_weight'  => 'float',
        'purchase'    => 'boolean',
        'sale'        => 'boolean',
        'stock'       => 'boolean',
        'raw'         => 'array',
        'is_active'   => 'boolean',
        'synced_at'   => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
