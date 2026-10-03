<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Gasto Meta atribuído a uma viatura num dia, por anúncio (tag [id:N]).
 * car_id null = viatura removida (tagged_car_id guarda o N original).
 * Sem relação com cascade para cars: o histórico sobrevive à viatura.
 */
class MetaAdCarSpendDaily extends Model
{
    public const TYPE_SINGLE = 'single';
    public const TYPE_SPLIT = 'split';

    protected $table = 'meta_ad_car_spend_daily';

    protected $fillable = [
        'company_id', 'account_id', 'date', 'campaign_id', 'adset_id', 'ad_id',
        'car_id', 'tagged_car_id', 'share', 'spend_allocated',
        'impressions_allocated', 'clicks_allocated', 'allocation_type',
    ];

    protected $casts = [
        'date'                  => 'date',
        'car_id'                => 'integer',
        'tagged_car_id'         => 'integer',
        'share'                 => 'float',
        'spend_allocated'       => 'float',
        'impressions_allocated' => 'float',
        'clicks_allocated'      => 'float',
    ];
}
