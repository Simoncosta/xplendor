<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use OwenIt\Auditing\Auditable;

/**
 * DMS — Cliente (scoped por company). Molde do Supplier.
 */
class Customer extends Model implements AuditableContract
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
        'citizen_card_number',
        'citizen_card_validity',
        'birth_date',
        'nationality',
        'profession',
        'marital_status',
        'contact_consent',
        'notes',
        'archived',
    ];

    protected $casts = [
        'citizen_card_validity' => 'date',
        'birth_date'            => 'date',
        'contact_consent'       => 'boolean',
        'archived'              => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
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

    // Vendas associadas — activa a regra de eliminação (cliente com vendas → arquivar).
    public function sales(): HasMany
    {
        return $this->hasMany(CarSale::class);
    }
}
