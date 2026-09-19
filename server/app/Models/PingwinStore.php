<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * XPLENDOR — Loja PingWin descoberta de uma empresa (uma empresa → N lojas).
 * A descoberta automática (fetch_stores) popula-as; guarda o último resumo de
 * vendas por loja. Scoped por company_id.
 */
class PingwinStore extends Model
{
    protected $fillable = [
        'company_id',
        'external_id',
        'code',
        'description',
        'city',
        'last_summary',
        'last_synced_at',
    ];

    protected $casts = [
        'last_summary'   => 'array',
        'last_synced_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
