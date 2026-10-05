<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pedido à IA (fila, registo e contador do limite mensal POR MODO). Nunca grava no
 * destino: o resultado é proposto no ecrã e o humano decide o que usa.
 *  · mode:    blog | brand_profile | creative | ideas;
 *  · variant: subtipo do modo (no blog: topic | from_post).
 * Guarda a versão do prompt, o modelo e os tokens de cada pedido.
 */
class AiRequest extends Model
{
    protected $table = 'ai_requests';

    public const MODE_BLOG = 'blog';
    public const MODE_BRAND_PROFILE = 'brand_profile';
    public const MODE_CREATIVE = 'creative';
    public const MODE_IDEAS = 'ideas';
    public const MODES = [self::MODE_BLOG, self::MODE_BRAND_PROFILE, self::MODE_CREATIVE, self::MODE_IDEAS];

    /** Subtipos do modo blog. */
    public const VARIANT_TOPIC = 'topic';
    public const VARIANT_FROM_POST = 'from_post';

    public const QUEUED = 'queued';
    public const PROCESSING = 'processing';
    public const DONE = 'done';
    public const ERROR = 'error';

    protected $fillable = [
        'company_id', 'blog_id', 'editorial_post_id', 'user_id', 'mode', 'variant', 'status', 'input', 'context', 'result',
        'model', 'prompt_version', 'prompt_tokens', 'completion_tokens', 'total_tokens', 'error_message',
        'dismissed_at', 'stalled_logged_at',
    ];

    protected $casts = [
        'input' => 'array',
        'context' => 'array',
        'result' => 'array',
        'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer',
        'total_tokens' => 'integer',
        'dismissed_at' => 'datetime',
        'stalled_logged_at' => 'datetime',
    ];

    public function isPending(): bool
    {
        return in_array($this->status, [self::QUEUED, self::PROCESSING], true);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
