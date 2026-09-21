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
 * XPLENDOR — PingWin Fornecedores (Fase 1): busca os fornecedores (READ-ONLY,
 * browserdataset paginado, LOGOUT garantido no Python) e guarda-os. Corre no
 * worker (com docker socket). Serializado por empresa (mesma chave das outras
 * sincronizações PingWin).
 */
class SyncPingwinSuppliersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;

    public function __construct(public int $companyId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("pingwin-sync:{$this->companyId}"))->releaseAfter(15)->expireAfter(900)];
    }

    public function handle(PingwinService $pingwin, AlertService $alerts): void
    {
        Log::info('[PingWin Fornecedores] Job iniciado', ['company_id' => $this->companyId]);
        try {
            $count = $pingwin->syncSuppliers($this->companyId);
        } catch (\Throwable $e) {
            Log::warning('[PingWin Fornecedores] Falhou', ['company_id' => $this->companyId, 'error' => $e->getMessage()]);
            $alerts->createSystemAlert(
                companyId: $this->companyId,
                type: 'warning',
                title: 'Falha ao sincronizar fornecedores',
                message: 'Não foi possível obter os fornecedores do PingWin. Tenta novamente.',
                severity: 'high',
                detailPath: '/restauracao/fornecedores',
            );

            return;
        }

        $alerts->createSystemAlert(
            companyId: $this->companyId,
            type: 'opportunity',
            title: 'Fornecedores atualizados',
            message: "Fornecedores do PingWin atualizados ({$count}).",
            severity: 'low',
            detailPath: '/restauracao/fornecedores',
        );
        Log::info('[PingWin Fornecedores] Job concluído', ['company_id' => $this->companyId, 'count' => $count]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('[PingWin Fornecedores] Job falhou', ['company_id' => $this->companyId, 'error' => $e->getMessage()]);
        app(AlertService::class)->createSystemAlert(
            companyId: $this->companyId,
            type: 'warning',
            title: 'Falha ao sincronizar fornecedores',
            message: 'Não foi possível obter os fornecedores do PingWin. Tenta novamente.',
            severity: 'high',
            detailPath: '/restauracao/fornecedores',
        );
    }
}
