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
 * XPLENDOR — PingWin Documentos (Fase 1): busca a lista de tipos de documento
 * (READ-ONLY, LOGOUT garantido no Python) e guarda-a. Corre no worker (com docker
 * socket). Serializado por empresa (mesma chave das outras sincronizações PingWin).
 */
class SyncPingwinDocumentsJob implements ShouldQueue
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
        Log::info('[PingWin Documentos] Job iniciado', ['company_id' => $this->companyId]);
        try {
            $count = $pingwin->syncDocuments($this->companyId);
        } catch (\Throwable $e) {
            Log::warning('[PingWin Documentos] Falhou', ['company_id' => $this->companyId, 'error' => $e->getMessage()]);
            $alerts->createSystemAlert(
                companyId: $this->companyId,
                type: 'warning',
                title: 'Falha ao sincronizar documentos',
                message: 'Não foi possível obter os tipos de documento do PingWin. Tenta novamente.',
                severity: 'high',
                detailPath: '/restauracao/documentos',
            );

            return;
        }

        $alerts->createSystemAlert(
            companyId: $this->companyId,
            type: 'opportunity',
            title: 'Documentos atualizados',
            message: "Tipos de documento do PingWin atualizados ({$count}).",
            severity: 'low',
            detailPath: '/restauracao/documentos',
        );
        Log::info('[PingWin Documentos] Job concluído', ['company_id' => $this->companyId, 'count' => $count]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('[PingWin Documentos] Job falhou', ['company_id' => $this->companyId, 'error' => $e->getMessage()]);
        app(AlertService::class)->createSystemAlert(
            companyId: $this->companyId,
            type: 'warning',
            title: 'Falha ao sincronizar documentos',
            message: 'Não foi possível obter os tipos de documento do PingWin. Tenta novamente.',
            severity: 'high',
            detailPath: '/restauracao/documentos',
        );
    }
}
