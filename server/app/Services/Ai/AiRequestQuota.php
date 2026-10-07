<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiRequest;

/**
 * Limite mensal de pedidos à IA por marca (empresa), POR FUNÇÃO (blog, perfil da marca,
 * criativos, ideias, legenda): uma função nunca gasta o limite de outra. Os erros com
 * resposta do fornecedor contam (o fornecedor trabalhou e cobrou); os que falharam sem
 * resposta (sem rede, tempo esgotado, configuração) não. Os limites vivem em
 * config/services.php (openai.ai_monthly_caps).
 */
class AiRequestQuota
{
    public static function cap(string $mode): int
    {
        $caps = (array) config('services.openai.ai_monthly_caps', []);

        return (int) ($caps[$mode] ?? 0);
    }

    public static function used(int $companyId, string $mode): int
    {
        return AiRequest::where('company_id', $companyId)
            ->where('mode', $mode)
            ->where('created_at', '>=', now()->startOfMonth())
            ->where(fn ($q) => $q->where('status', '!=', AiRequest::ERROR)->orWhereNotNull('provider_status'))
            ->count();
    }

    public static function exhausted(int $companyId, string $mode): bool
    {
        return self::used($companyId, $mode) >= self::cap($mode);
    }
}
