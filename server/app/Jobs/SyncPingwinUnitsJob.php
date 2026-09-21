<?php

namespace App\Jobs;

use App\Services\AlertService;
use App\Services\PingwinService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — PingWin Unidades (Fase 1): busca as unidades (READ-ONLY, porta 8138,
 * LOGOUT garantido no Python) e guarda-as. Corre no worker (com docker socket).
 * Serializado por empresa (mesma chave das outras sincronizações PingWin).
 */
class SyncPingwinUnitsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 240;

    public function __construct(public int $companyId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("pingwin-sync:{$this->companyId}"))->releaseAfter(15)->expireAfter(600)];
    }

    public function handle(PingwinService $pingwin, AlertService $alerts): void
    {
        Log::info('[PingWin Unidades] Job iniciado', ['company_id' => $this->companyId]);
        try {
            $count = $pingwin->syncUnits($this->companyId);
        } catch (\Throwable $e) {
            Log::warning('[PingWin Unidades] Falhou', ['company_id' => $this->companyId, 'error' => $e->getMessage()]);
            $alerts->createSystemAlert(
                companyId: $this->companyId,
                type: 'warning',
                title: 'Falha ao sincronizar unidades',
                message: 'Não foi possível obter as unidades do PingWin. Tenta novamente.',
                severity: 'high',
                detailPath: '/restauracao/unidades',
            );

            return;
        }

        $alerts->createSystemAlert(
            companyId: $this->companyId,
            type: 'opportunity',
            title: 'Unidades atualizadas',
            message: "Unidades do PingWin atualizadas ({$count}).",
            severity: 'low',
            detailPath: '/restauracao/unidades',
        );
        Log::info('[PingWin Unidades] Job concluído', ['company_id' => $this->companyId, 'count' => $count]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('[PingWin Unidades] Job falhou', ['company_id' => $this->companyId, 'error' => $e->getMessage()]);
        app(AlertService::class)->createSystemAlert(
            companyId: $this->companyId,
            type: 'warning',
            title: 'Falha ao sincronizar unidades',
            message: 'Não foi possível obter as unidades do PingWin. Tenta novamente.',
            severity: 'high',
            detailPath: '/restauracao/unidades',
        );
    }
}
