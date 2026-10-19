<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant;

use App\Audit\AuditRuleBase;

/** Base das regras da restauração: área "restauracao", só para empresas com o PingWin ligado. */
abstract class RestaurantRule extends AuditRuleBase
{
    public function area(): string
    {
        return 'restauracao';
    }

    public function appliesTo(int $companyId): bool
    {
        return RestaurantAuditData::enabled($companyId);
    }

    protected static function articleUrl(string $pid): string
    {
        return "/cadastros/artigos/{$pid}";
    }

    protected static function documentUrl(string $docheaderId): string
    {
        return '/restauracao/faturas?tab=pingwin&doc=' . rawurlencode($docheaderId);
    }

    protected static function recipeUrl(string $pid, array $query = []): string
    {
        return "/restauracao/fichas-tecnicas/{$pid}" . ($query ? '?' . http_build_query($query) : '');
    }
}
