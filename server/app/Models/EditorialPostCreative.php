<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Criativo aceite de uma publicação da Linha Editorial (o humano escolheu campo a campo).
 * Quando o publicador (F2) existir, pré-preenche a legenda e o formato da publicação real.
 */
class EditorialPostCreative extends Model
{
    public const SOURCE_MARKET_REFERENCE = 'market_reference';
    public const SOURCE_OWN_HISTORY = 'own_history';
    public const SOURCE_NONE = 'none';

    protected $fillable = [
        'company_id', 'editorial_post_id', 'media_format', 'hook', 'caption', 'hashtags', 'cta',
        'rationale', 'source', 'source_label', 'ai_request_id', 'accepted_by_user_id', 'accepted_at',
    ];

    protected $casts = [
        'hashtags' => 'array',
        'accepted_at' => 'datetime',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(EditorialPost::class, 'editorial_post_id');
    }
}
