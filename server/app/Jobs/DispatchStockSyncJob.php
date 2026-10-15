<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CompanyIntegration;
use App\Services\Restaurant\StockSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * XPLENDOR — Stock do PingWin (Fatia 7a): despacho NOTURNO (04:15, Lisboa). Um ciclo por empresa com
 * PingWin ligado: os armazéns e a lista de artigos a ler, em lotes encadeados (SyncPingwinStockJob).
 */
class DispatchStockSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 60; // só despacha; não fala com o PingWin

    public function handle(StockSyncService $sync): void
    {
        $companyIds = CompanyIntegration::where('platform', 'pingwin')->where('status', '!=', 'revoked')
            ->pluck('company_id')->map(fn ($id) => (int) $id)->unique()->values()->all();

        foreach ($companyIds as $companyId) {
            $ids = $sync->candidates($companyId);
            $run = $sync->startRun($companyId, 'agendada', count($ids));
            SyncPingwinStockJob::dispatch($companyId, $run->id, $ids, true);
        }
    }
}
