<?php

declare(strict_types=1);

namespace App\Services\Financing;

use Illuminate\Support\Facades\DB;

/**
 * Os links de documentos criados antes dos pedidos com titulares: cada um passa a ser o titular 1
 * de um pedido de financiamento novo, com a mesma lista, as mesmas pastas e a mesma retenção.
 * Idempotente (só os links sem pedido). Corre na migração 2027_02_20_100000.
 */
final class FinancingRequestBackfill
{
    public static function run(): int
    {
        $n = 0;
        foreach (DB::table('financing_links')->whereNull('financing_request_id')->orderBy('id')->get() as $link) {
            $requested = json_decode((string) $link->requested, true) ?: [];
            $requestId = DB::table('financing_requests')->insertGetId([
                'company_id' => $link->company_id, 'car_id' => $link->car_id, 'car_lead_id' => $link->car_lead_id,
                'created_by_user_id' => $link->created_by_user_id, 'purpose' => 'financiamento',
                'items' => json_encode(array_values((array) ($requested['items'] ?? []))), 'other_description' => $requested['other'] ?? null,
                'folder_id' => $link->folder_id, 'last_upload_at' => $link->last_upload_at, 'last_notified_at' => $link->last_notified_at,
                'purge_warned_at' => $link->purge_warned_at, 'purged_at' => $link->purged_at,
                'created_at' => $link->created_at, 'updated_at' => $link->updated_at,
            ]);
            DB::table('financing_links')->where('id', $link->id)->update(['financing_request_id' => $requestId, 'holder' => 1]);
            $n++;
        }

        return $n;
    }
}
