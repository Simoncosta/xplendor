<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Gasto/impressões/cliques de um anúncio Meta por dia (ingestão ao nível do
 * ANÚNCIO — base da atribuição de gasto por viatura pela tag [id:N]).
 */
class MetaAdInsightDaily extends Model
{
    protected $table = 'meta_ad_insights_daily';

    protected $fillable = [
        'company_id', 'account_id', 'date', 'campaign_id', 'adset_id', 'ad_id', 'ad_name',
        'spend', 'impressions', 'clicks',
    ];

    protected $casts = [
        'date'        => 'date',
        'spend'       => 'decimal:2',
        'impressions' => 'integer',
        'clicks'      => 'integer',
    ];
}
