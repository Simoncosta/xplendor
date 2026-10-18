<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * XPLENDOR — Auditoria v2: o perfil de unidades de um artigo, a fonte de verdade na XPLENDOR. A
 * base (kg | L | un), o tipo de artigo, a unidade de contagem e o estado. Nada passa a confirmado
 * sem uma pessoa carregar em "Confirmar"; o PingWin só muda com "Aplicar no PingWin".
 * As conversões (compra por fornecedor e contagem) ficam em product_unit_conversions (profile_id).
 */
class ProductUnitProfile extends Model
{
    public const STATES = ['por_identificar', 'sugerido', 'confirmado', 'aplicado_no_pingwin', 'nao_se_aplica'];
    public const TYPES = ['peso', 'volume', 'contavel', 'embalado_peso', 'embalado_inteiro'];
    public const BASES = ['kg', 'L', 'un'];
    /** Abaixo disto, o artigo fica "Por identificar" (U8). */
    public const MIN_CONFIDENCE = 0.6;

    protected $fillable = [
        'company_id', 'product_pingwin_id', 'base_proposed', 'base_confirmed', 'article_type', 'count_unit', 'count_factor',
        'state', 'confirmed_by', 'confirmed_at', 'reason', 'confidence', 'evidence', 'proposal', 'answers',
        'info_text', 'info_photo_path', 'ai_suggestion', 'ai_suggested_at', 'ai_signature', 'skipped_at',
    ];

    protected $casts = [
        'count_factor' => 'float',
        'confidence' => 'float',
        'evidence' => 'array',
        'proposal' => 'array',
        'answers' => 'array',
        'ai_suggestion' => 'array',
        'confirmed_at' => 'datetime',
        'ai_suggested_at' => 'datetime',
        'skipped_at' => 'datetime',
    ];

    public function events(): HasMany
    {
        return $this->hasMany(ProductUnitProfileEvent::class, 'profile_id')->orderByDesc('id');
    }

    public function conversions(): HasMany
    {
        return $this->hasMany(ProductUnitConversion::class, 'profile_id');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function isDecided(): bool
    {
        return in_array($this->state, ['confirmado', 'aplicado_no_pingwin', 'nao_se_aplica'], true);
    }
}
