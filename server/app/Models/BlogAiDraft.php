<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pedido de rascunho à IA ("Ajudar a escrever" ou "a partir de uma publicação"). Nunca grava
 * no artigo: o resultado é proposto no editor e o humano decide o que usa.
 */
class BlogAiDraft extends Model
{
    public const MODE_TOPIC = 'topic';
    public const MODE_FROM_POST = 'from_post';

    public const QUEUED = 'queued';
    public const PROCESSING = 'processing';
    public const DONE = 'done';
    public const ERROR = 'error';

    protected $fillable = [
        'company_id', 'blog_id', 'user_id', 'mode', 'status', 'input', 'context', 'result',
        'model', 'prompt_version', 'prompt_tokens', 'completion_tokens', 'total_tokens', 'error_message',
    ];

    protected $casts = [
        'input' => 'array',
        'context' => 'array',
        'result' => 'array',
        'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer',
        'total_tokens' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
