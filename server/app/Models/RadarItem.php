<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Radar: um item lido de um feed público. Só o título, a ligação, a fonte, a hora e o volume
 * aproximado (com o extra que a especificação permite: as notícias relacionadas do Trends, as
 * categorias curtas dos jornais, o aviso do IPMA), nunca o corpo do artigo.
 */
class RadarItem extends Model
{
    public const VERDICT_PASSED = 'passed';
    public const VERDICT_BLOCKED = 'blocked';

    protected $fillable = [
        'source_key', 'external_key', 'title', 'url', 'source_name', 'published_at', 'fetched_at', 'approx_volume',
        'approx_volume_label', 'extra', 'filter_verdict', 'filter_reasons', 'filter_flags', 'topic_id', 'expires_at',
    ];

    protected $casts = [
        'published_at' => 'datetime', 'fetched_at' => 'datetime', 'expires_at' => 'datetime', 'approx_volume' => 'integer',
        'extra' => 'array', 'filter_reasons' => 'array', 'filter_flags' => 'array', 'topic_id' => 'integer',
    ];

    public function topic(): BelongsTo
    {
        return $this->belongsTo(RadarTopic::class, 'topic_id');
    }
}
