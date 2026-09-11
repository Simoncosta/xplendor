<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use OwenIt\Auditing\Auditable;

/**
 * DMS sub-fase 1c.2a — Categoria de Despesa (scoped por company).
 */
class ExpenseCategory extends Model implements AuditableContract
{
    use Auditable;

    protected $fillable = [
        'company_id',
        'name',
        'color',
        'archived',
    ];

    protected $casts = [
        'archived' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    // 1c.2b — despesas classificadas nesta categoria. Permite withCount('expenses')
    // e activa a regra de eliminação (categoria com despesas → só arquivar).
    public function expenses(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
