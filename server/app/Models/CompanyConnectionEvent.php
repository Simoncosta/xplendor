<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Histórico das ligações da empresa (redes sociais, anúncios da Meta, GA4): o que foi ligado,
 * mudado ou desligado, quando e por que origem (um utilizador ou o link de configuração).
 */
class CompanyConnectionEvent extends Model
{
    public const UPDATED_AT = null;

    public const KIND_SOCIAL = 'social';
    public const KIND_META_ADS = 'meta_ads';
    public const KIND_GA4 = 'ga4';

    public const CONNECTED = 'connected';
    public const ACCOUNT_CHANGED = 'account_changed';
    public const DISCONNECTED = 'disconnected';

    protected $fillable = ['company_id', 'kind', 'action', 'setup_link_id', 'user_id', 'detail', 'created_at'];

    protected $casts = ['detail' => 'array', 'created_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Regista um evento; nunca interrompe a ação principal. */
    public static function record(int $companyId, string $kind, string $action, ?int $userId = null, ?int $setupLinkId = null, array $detail = []): void
    {
        try {
            self::create([
                'company_id' => $companyId, 'kind' => $kind, 'action' => $action, 'user_id' => $userId,
                'setup_link_id' => $setupLinkId, 'detail' => $detail ?: null, 'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Ligações] Falha ao registar o histórico', ['company_id' => $companyId, 'kind' => $kind, 'error' => $e->getMessage()]);
        }
    }
}
