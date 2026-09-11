<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use OwenIt\Auditing\Auditable;

/**
 * DMS sub-fase 1c.2b — Despesa (scoped por company).
 */
class Expense extends Model implements AuditableContract
{
    use Auditable;

    protected $fillable = [
        'company_id',
        'description',
        'amount',
        'date',
        'expense_category_id',
        'supplier_id',
        'car_id',
        'is_paid',
        'paid_at',
        'archived',
        'notes',
    ];

    protected $casts = [
        'amount'   => 'decimal:2',
        'date'     => 'date',
        'paid_at'  => 'date',
        'is_paid'  => 'boolean',
        'archived' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    /** Tem algum vínculo (fornecedor/viatura/categoria)? Se não, pode eliminar-se livremente. */
    public function hasLinks(): bool
    {
        return $this->supplier_id !== null
            || $this->car_id !== null
            || $this->expense_category_id !== null;
    }
}
