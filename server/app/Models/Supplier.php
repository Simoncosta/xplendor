<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use OwenIt\Auditing\Auditable;

/**
 * DMS sub-fase 1c.1 — Fornecedor NATIVO (automotivo), scoped por company.
 *
 * ⚠️ Tabela UNIFICADA `suppliers` com flag `source`. Este model é a janela dos
 * MANUAIS: um global scope fixa source='manual' e força-o na criação. Os do
 * PingWin vivem na MESMA tabela mas veem-se pelo model PingwinSupplier
 * (source='pingwin'). Assim o automotivo e o desacoplamento (sem PingWin) usam
 * só os manuais, e o sync do PingWin nunca lhes toca.
 */
class Supplier extends Model implements AuditableContract
{
    use Auditable;

    protected $fillable = [
        'company_id',
        'source',
        'name',
        'nif',
        'phone',
        'email',
        'address',
        'postal_code',
        'district_id',
        'municipality_id',
        'parish_id',
        'iban',
        'notes',
        'archived', // 1c.2b — arquivo quando tem despesas associadas
    ];

    protected $casts = [
        'archived' => 'boolean',
    ];

    /** Só os manuais; e todo o Supplier criado por aqui nasce source='manual'. */
    protected static function booted(): void
    {
        static::addGlobalScope('manual', fn (Builder $q) => $q->where($q->getModel()->getTable() . '.source', 'manual'));
        static::creating(function (Supplier $s) {
            if (empty($s->source)) {
                $s->source = 'manual';
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    // 1c.2b — despesas deste fornecedor. Activa a regra de eliminação
    // (fornecedor com despesas → só arquivar).
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function parish(): BelongsTo
    {
        return $this->belongsTo(Parish::class);
    }
}
