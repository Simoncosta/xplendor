<?php

declare(strict_types=1);

namespace App\Services\Tracking;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — os pedidos recusados pelo /api/public/track, agregados por dia (Lisboa), empresa,
 * tipo, estado e motivo. Sem dados pessoais: o motivo leva só os nomes dos campos. Nunca parte o
 * tracking: um erro aqui fica só no log.
 */
final class TrackingRejections
{
    public static function record(int $companyId, string $type, int $status, string $reason): void
    {
        try {
            $now = now();
            $grammar = DB::getQueryGrammar();
            DB::table('tracking_rejections_daily')->upsert([[
                'company_id' => $companyId, 'day' => CarbonImmutable::now('Europe/Lisbon')->toDateString(),
                'event_type' => mb_substr($type, 0, 40), 'status' => $status, 'reason' => mb_substr($reason, 0, 120),
                'count' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]], ['company_id', 'day', 'event_type', 'status', 'reason'], [
                'count' => DB::raw($grammar->wrap('count') . ' + 1'), 'updated_at' => $now,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[Track] contagem de recusas falhou', ['error' => $e->getMessage()]);
        }
    }
}
