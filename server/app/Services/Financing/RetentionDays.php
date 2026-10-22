<?php

declare(strict_types=1);

namespace App\Services\Financing;

use App\Models\Company;
use App\Models\FinancingRequest;
use Carbon\CarbonInterface;

/** Os dias até ao apagamento automático (por empresa, dentro dos limites) e a data de cada pedido. */
final class RetentionDays
{
    public static function for(?Company $company): int
    {
        $days = (int) ($company?->financing_retention_days ?: config('financing.retention_days_default'));

        return max((int) config('financing.retention_days_min'), min((int) config('financing.retention_days_max'), $days));
    }

    /** O dia em que os documentos de um pedido se apagam (null sem envios ou já apagados): conta do último envio de qualquer titular. */
    public static function purgeOn(FinancingRequest $request): ?CarbonInterface
    {
        if (! $request->last_upload_at || $request->purged_at) {
            return null;
        }

        return $request->last_upload_at->copy()->addDays(self::for($request->company))->startOfDay();
    }
}
