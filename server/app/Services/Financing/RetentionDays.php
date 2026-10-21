<?php

declare(strict_types=1);

namespace App\Services\Financing;

use App\Models\Company;
use App\Models\FinancingLink;
use Carbon\CarbonInterface;

/** Os dias até ao apagamento automático (por empresa, dentro dos limites) e a data de cada pedido. */
final class RetentionDays
{
    public static function for(?Company $company): int
    {
        $days = (int) ($company?->financing_retention_days ?: config('financing.retention_days_default'));

        return max((int) config('financing.retention_days_min'), min((int) config('financing.retention_days_max'), $days));
    }

    /** O dia em que os documentos de um pedido se apagam (null sem envios ou já apagados). */
    public static function purgeOn(FinancingLink $link): ?CarbonInterface
    {
        if (! $link->last_upload_at || $link->purged_at) {
            return null;
        }

        return $link->last_upload_at->copy()->addDays(self::for($link->company))->startOfDay();
    }
}
