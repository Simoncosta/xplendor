<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetaAudienceInsight extends Model
{
    protected $fillable = [
        'company_id',
        'car_id',
        'period_start',
        'period_end',
        'age_range',
        'gender',
        'impressions',
        'clicks',
        'spend',
        'reach',
        'campaign_targeting_json',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end'   => 'date',
        'spend'        => 'decimal:2',
        'campaign_targeting_json' => 'array',
    ];

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }
}
