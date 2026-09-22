<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * XPLENDOR — Fornecedor do PingWin (sincronizado). ⚠️ Após a unificação vive na
 * MESMA tabela `suppliers`, distinguido por source='pingwin'. Este model é a
 * janela dos PingWin: aponta para `suppliers`, um global scope fixa
 * source='pingwin' e força-o na criação. UNIQUE (company_id, pingwin_id) →
 * UPSERT idempotente no sync (que nunca casa nem apaga os source='manual').
 */
class PingwinSupplier extends Model
{
    protected $table = 'suppliers';

    protected $fillable = [
        'company_id', 'source', 'pingwin_id', 'code', 'name', 'fiscal_name', 'tax_number',
        'address', 'city', 'postal_code', 'phone', 'email', 'is_active', 'synced_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'synced_at' => 'datetime',
    ];

    /** Só os do PingWin; e todo o registo criado por aqui nasce source='pingwin'. */
    protected static function booted(): void
    {
        static::addGlobalScope('pingwin', fn (Builder $q) => $q->where($q->getModel()->getTable() . '.source', 'pingwin'));
        static::saving(function (PingwinSupplier $s) {
            $s->source = 'pingwin';
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
