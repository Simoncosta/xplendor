<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use OwenIt\Auditing\Auditable;

/**
 * DMS sub-fase 1c.1 — Fornecedor (scoped por company).
 */
class Supplier extends Model implements AuditableContract
{
    use Auditable;

    protected $fillable = [
        'company_id',
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
