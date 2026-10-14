<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Radar: o cartão de uma empresa ("Está a subir: X"), no máximo 3 por dia. */
class RadarSignal extends Model
{
    public const NEW = 'new';
    public const REACTED = 'reacted';
    public const IGNORED = 'ignored';
    public const MUTED = 'muted';

    protected $fillable = ['company_id', 'topic_id', 'why', 'why_source', 'window_until', 'status', 'score', 'flags', 'sources',
        'editorial_post_id', 'acted_at', 'acted_by_user_id'];

    protected $casts = ['window_until' => 'datetime', 'acted_at' => 'datetime', 'flags' => 'array', 'sources' => 'array', 'score' => 'integer'];

    public function topic(): BelongsTo
    {
        return $this->belongsTo(RadarTopic::class, 'topic_id');
    }
}
