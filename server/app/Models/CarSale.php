<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarSale extends Model
{
    protected $fillable = [
        'car_id',
        'company_id',
        'customer_id',
        'lead_id',
        'sale_price',
        'advertised_price',
        'discount_amount',
        'offers',
        'has_financing',
        'financing_entity',
        'financed_amount',
        'has_trade_in',
        'trade_in_vehicle',
        'trade_in_value',
        'first_motorhome',
        'previous_vehicle',
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
        'advertised_price' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'financed_amount' => 'decimal:2',
        'trade_in_value' => 'decimal:2',
        'has_financing' => 'boolean',
        'has_trade_in' => 'boolean',
        'first_motorhome' => 'boolean',
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

    // DMS — cliente associado à venda (nullable; vendas antigas ficam sem).
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    // Lead que originou a venda (nullable). Fase 2 popula; a origem da venda
    // herda-se desta lead (channel/utm) quando ligada.
    public function lead(): BelongsTo
    {
        return $this->belongsTo(CarLead::class, 'lead_id');
    }
}
