<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma rede escolhida numa publicação (Instagram ou Facebook): o formato nessa rede e,
 * depois de publicada, o link, a hora real e quem marcou; ou "Não publicar nesta rede",
 * com o motivo. Na F2, cada linha dá uma publicação na rede (social_publications).
 */
class EditorialPostNetwork extends Model
{
    public const STATE_PENDING = 'pending';
    public const STATE_PUBLISHED = 'published';
    public const STATE_SKIPPED = 'skipped';

    protected $fillable = [
        'company_id', 'editorial_post_id', 'network', 'media_format', 'position',
        'published_url', 'published_at', 'published_by_user_id', 'published_by_impersonator_id',
        'skipped_at', 'skip_reason', 'skipped_by_user_id', 'skipped_by_impersonator_id',
    ];

    protected $casts = ['published_at' => 'datetime', 'skipped_at' => 'datetime', 'position' => 'integer'];

    public function state(): string
    {
        return $this->skipped_at ? self::STATE_SKIPPED : ($this->published_at ? self::STATE_PUBLISHED : self::STATE_PENDING);
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(EditorialPost::class, 'editorial_post_id');
    }
}
