<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Radar: a tendência que junta itens (um item do Trends, notícias de dois ou mais jornais, um
 * aviso do IPMA por distrito e nível, uma decisão do BCE), com a janela até quando vale e o
 * veredicto do filtro herdado dos itens (um item bloqueado bloqueia a tendência).
 */
class RadarTopic extends Model
{
    public const KINDS = ['trend', 'news', 'weather', 'bce'];

    protected $fillable = [
        'kind', 'title', 'normalized_key', 'first_seen_at', 'last_seen_at', 'approx_volume', 'approx_volume_label', 'sources',
        'districts', 'filter_verdict', 'filter_reasons', 'filter_flags', 'window_until', 'expires_at',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'window_until' => 'datetime', 'expires_at' => 'datetime',
        'approx_volume' => 'integer', 'sources' => 'array', 'districts' => 'array', 'filter_reasons' => 'array', 'filter_flags' => 'array',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(RadarItem::class, 'topic_id');
    }

    public function relevances(): HasMany
    {
        return $this->hasMany(RadarRelevance::class, 'topic_id');
    }
}
