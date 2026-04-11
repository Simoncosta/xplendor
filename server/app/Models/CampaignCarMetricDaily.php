<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignCarMetricDaily extends Model
{
    use HasFactory;

    protected $table = 'campaign_car_metrics_daily';

    protected $fillable = [
        'company_id',
        'car_id',
        'mapping_id',
        'campaign_id',
        'adset_id',
        'date',
        'impressions',
        'clicks',
        'spend_normalized',
        'ctr',
        'cpc',
        'cpm',
        'allocation_factor',
    ];

    protected $casts = [
        'date' => 'date',
        'impressions' => 'integer',
        'clicks' => 'integer',
        'spend_normalized' => 'decimal:2',
        'ctr' => 'decimal:2',
        'cpc' => 'decimal:4',
        'cpm' => 'decimal:2',
        'allocation_factor' => 'decimal:6',
    ];

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    public function mapping(): BelongsTo
    {
        return $this->belongsTo(CarAdCampaign::class, 'mapping_id');
    }
}
