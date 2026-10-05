<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiRequest;

/**
 * Limite mensal de pedidos à IA por empresa, POR MODO (blog, perfil da marca,
 * criativos): um modo nunca gasta o limite de outro. Os pedidos que falharam não
 * contam. Os limites vivem em config/services.php (openai.ai_monthly_caps).
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
            ->where('status', '!=', AiRequest::ERROR)
            ->count();
    }

    public static function exhausted(int $companyId, string $mode): bool
    {
        return self::used($companyId, $mode) >= self::cap($mode);
    }
}
