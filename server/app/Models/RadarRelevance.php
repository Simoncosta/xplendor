<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Radar: a avaliação de uma tendência para um ramo (uma vez por tendência e por ramo). */
class RadarRelevance extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['topic_id', 'sector_slug', 'relevant', 'score', 'theme', 'reason', 'model', 'effort', 'input_tokens', 'output_tokens', 'cost_usd'];

    protected $casts = ['relevant' => 'boolean', 'score' => 'integer', 'input_tokens' => 'integer', 'output_tokens' => 'integer', 'cost_usd' => 'float'];

    public function topic(): BelongsTo
    {
        return $this->belongsTo(RadarTopic::class, 'topic_id');
    }
}
