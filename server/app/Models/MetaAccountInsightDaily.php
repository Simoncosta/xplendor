<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Gasto/impressões/cliques de uma conta de anúncios Meta, por campanha e por dia
 * (ingestão ao nível da CONTA — serve todas as verticais, não só carros).
 */
class MetaAccountInsightDaily extends Model
{
    protected $table = 'meta_account_insights_daily';

    protected $fillable = [
        'company_id', 'account_id', 'date', 'campaign_id', 'campaign_name',
        'spend', 'impressions', 'clicks',
    ];

    protected $casts = [
        'date'        => 'date',
        'spend'       => 'decimal:2',
        'impressions' => 'integer',
        'clicks'      => 'integer',
    ];
}
