<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarSale extends Model
{
    protected $fillable = [
        'car_id',
        'company_id',
        'sale_price',
        'buyer_gender',
        'buyer_age_range',
        'sale_channel',
        'buyer_name',
        'buyer_phone',
        'buyer_email',
        'contact_consent',
        'notes',
        'sold_at',
    ];

    protected $casts = [
        'sale_price' => 'decimal:2',
        'contact_consent' => 'boolean',
        'sold_at' => 'datetime',
    ];

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
