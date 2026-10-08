<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pedido à IA (fila, registo e contador do limite mensal POR MODO). Nunca grava no
 * destino: o resultado é proposto no ecrã e o humano decide o que usa.
 *  · mode:    a função (blog | brand_profile | creative | ideas | caption | car_description | car_analysis | family_categories);
 *  · variant: subtipo do modo (no blog: topic | from_post).
 * Guarda a versão do prompt, o fornecedor, o modelo e o esforço, os tokens (entrada, saída e
 * raciocínio), o custo calculado e o resultado do verificador do português de Portugal.
 */
class AiRequest extends Model
{
    protected $table = 'ai_requests';

    public const MODE_BLOG = 'blog';
    public const MODE_BRAND_PROFILE = 'brand_profile';
    public const MODE_CREATIVE = 'creative';
    public const MODE_IDEAS = 'ideas';
    public const MODE_CAPTION = 'caption';
    public const MODE_CAR_DESCRIPTION = 'car_description';
    public const MODE_CAR_ANALYSIS = 'car_analysis';
    public const MODE_FAMILY_CATEGORIES = 'family_categories';
    public const MODE_BUSSOLA = 'bussola_jogadas';
    public const MODES = [self::MODE_BLOG, self::MODE_BRAND_PROFILE, self::MODE_CREATIVE, self::MODE_IDEAS, self::MODE_CAPTION, self::MODE_CAR_DESCRIPTION, self::MODE_CAR_ANALYSIS, self::MODE_FAMILY_CATEGORIES, self::MODE_BUSSOLA];

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
        'provider', 'effort', 'input_tokens', 'output_tokens', 'reasoning_tokens', 'cost_usd', 'provider_status', 'pt_issues', 'pt_retried', 'car_id',
    ];

    protected $casts = [
        'input' => 'array',
        'context' => 'array',
        'result' => 'array',
        'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer',
        'total_tokens' => 'integer',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'reasoning_tokens' => 'integer',
        'cost_usd' => 'float',
        'provider_status' => 'integer',
        'pt_issues' => 'array',
        'pt_retried' => 'boolean',
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
