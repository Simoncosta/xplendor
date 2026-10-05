<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Regra de formato (referência de mercado) por rede e faixa de seguidores: que formato
 * tende a ter mais interação. Editável só pelo root. A fonte acompanha sempre a regra.
 */
class CreativeFormatRule extends Model
{
    protected $fillable = [
        'channel', 'followers_min', 'followers_max', 'format_key', 'engagement_rate', 'rank',
        'note', 'source_label', 'source_url', 'is_active', 'updated_by_user_id',
    ];

    protected $casts = [
        'followers_min' => 'integer',
        'followers_max' => 'integer',
        'engagement_rate' => 'float',
        'rank' => 'integer',
        'is_active' => 'boolean',
    ];

    /** Regras ativas da rede cuja faixa contém este número de seguidores. */
    public function scopeForFollowers(Builder $q, string $channel, int $followers): Builder
    {
        return $q->where('channel', $channel)
            ->where('is_active', true)
            ->where('followers_min', '<=', $followers)
            ->where(fn ($w) => $w->whereNull('followers_max')->orWhere('followers_max', '>=', $followers));
    }
}
