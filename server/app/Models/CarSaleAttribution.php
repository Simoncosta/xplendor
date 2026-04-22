<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarSaleAttribution extends Model
{
    protected $fillable = [
        'company_id',
        'car_id',
        'sold_at',
        'sale_price',
        'attributed_platform',
        'attributed_campaign_id',
        'attributed_adset_id',
        'attributed_ad_id',
        'attribution_model',
        'attribution_window_days',
        'match_type',
        'time_to_sale_hours',
        'time_from_last_interaction_hours',
        'confidence_score',
        'confidence_reason',
        'source_snapshot',
    ];

    protected $casts = [
        'sold_at' => 'datetime',
        'sale_price' => 'decimal:2',
        'source_snapshot' => 'array',
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
