<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CompanyIntegration;
use App\Services\PingwinBomSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * XPLENDOR — Fichas técnicas (Fatia 1): despacho NOTURNO. Um ciclo por empresa com PingWin ligado:
 * a lista de artigos com ficha, lida em lotes encadeados (SyncPingwinBomsJob).
 */
class DispatchBomSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 60; // só despacha; não fala com o PingWin

    public function handle(PingwinBomSyncService $sync): void
    {
        $companyIds = CompanyIntegration::where('platform', 'pingwin')->where('status', '!=', 'revoked')
            ->pluck('company_id')->map(fn ($id) => (int) $id)->unique()->values()->all();

        foreach ($companyIds as $companyId) {
            $ids = $sync->candidates($companyId);
            if ($ids) {
                SyncPingwinBomsJob::dispatch($companyId, $ids, []);
            }
        }
    }
}
