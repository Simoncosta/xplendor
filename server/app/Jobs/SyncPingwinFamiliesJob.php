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
 * XPLENDOR — PingWin Famílias (Fase 1): busca a árvore de famílias (READ-ONLY,
 * LOGOUT garantido no Python), guarda-a FLAT e RELIGA os artigos existentes.
 * Corre no worker (com docker socket). Serializado por empresa (mesma chave das
 * outras sincronizações PingWin).
 */
class SyncPingwinFamiliesJob implements ShouldQueue
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
        Log::info('[PingWin Famílias] Job iniciado', ['company_id' => $this->companyId]);
        try {
            $count = $pingwin->syncFamilies($this->companyId);
        } catch (\Throwable $e) {
            Log::warning('[PingWin Famílias] Falhou', ['company_id' => $this->companyId, 'error' => $e->getMessage()]);
            $alerts->createSystemAlert(
                companyId: $this->companyId,
                type: 'warning',
                title: 'Falha ao sincronizar famílias',
                message: 'Não foi possível obter as famílias do PingWin. Tenta novamente.',
                severity: 'high',
                detailPath: '/restauracao/familias',
            );

            return;
        }

        $alerts->createSystemAlert(
            companyId: $this->companyId,
            type: 'opportunity',
            title: 'Famílias atualizadas',
            message: "Famílias do PingWin atualizadas ({$count}) e artigos religados.",
            severity: 'low',
            detailPath: '/restauracao/familias',
        );
        Log::info('[PingWin Famílias] Job concluído', ['company_id' => $this->companyId, 'count' => $count]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('[PingWin Famílias] Job falhou', ['company_id' => $this->companyId, 'error' => $e->getMessage()]);
        app(AlertService::class)->createSystemAlert(
            companyId: $this->companyId,
            type: 'warning',
            title: 'Falha ao sincronizar famílias',
            message: 'Não foi possível obter as famílias do PingWin. Tenta novamente.',
            severity: 'high',
            detailPath: '/restauracao/familias',
        );
    }
}
