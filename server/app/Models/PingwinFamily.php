<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * XPLENDOR — Família de artigos do PingWin, só leitura. Guardada FLAT (com
 * parent_pingwin_id); a árvore monta-se na exibição. UNIQUE (company_id,
 * pingwin_id) → UPSERT idempotente no sync.
 */
class PingwinFamily extends Model
{
    protected $fillable = [
        'company_id', 'pingwin_id', 'description', 'parent_pingwin_id', 'is_active', 'synced_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'synced_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Artigos ligados a esta família (por family_id, religado após o sync). */
    public function items(): HasMany
    {
        return $this->hasMany(PingwinCatalogItem::class, 'family_id');
    }
}
