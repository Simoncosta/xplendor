<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarSalesLearning extends Model
{
    protected $table = 'car_sales_learning';

    protected $fillable = [
        'company_id',
        'car_id',
        'sold_at',
        'sale_price',
        'buyer_age',
        'buyer_gender',
        'contact_signal_score',
        'contact_signal_level',
        'peak_contact_signal_last_7d',
        'peak_contact_signal_at',
        'contact_signal_trend',
        'sessions_last_7d',
        'views_last_7d',
        'whatsapp_clicks_last_7d',
        'leads_last_7d',
        'primary_contact_channel',
        'campaign_ids',
        'ad_ids',
        'adset_ids',
        'price_at_sale',
        'days_in_stock',
        'sale_quality_score',
    ];

    protected $casts = [
        'sold_at' => 'datetime',
        'peak_contact_signal_at' => 'datetime',
        'sale_price' => 'decimal:2',
        'campaign_ids' => 'array',
        'ad_ids' => 'array',
        'adset_ids' => 'array',
        'price_at_sale' => 'decimal:2',
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
