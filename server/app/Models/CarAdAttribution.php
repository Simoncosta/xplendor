<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarAdAttribution extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'car_id',
        'dealer_id',
        'source',
        'platform',
        'campaign_id',
        'adset_id',
        'ad_id',
        'visitor_id',
        'session_id',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'utm_id',
        'click_id',
        'first_interaction_at',
        'last_interaction_at',
        'has_whatsapp_click',
        'has_lead',
        'has_strong_intent',
    ];

    protected $casts = [
        'first_interaction_at' => 'datetime',
        'last_interaction_at' => 'datetime',
        'has_whatsapp_click' => 'boolean',
        'has_lead' => 'boolean',
        'has_strong_intent' => 'boolean',
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
