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
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end'   => 'date',
        'spend'        => 'decimal:2',
    ];

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }
}
