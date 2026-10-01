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
 * XPLENDOR — PingWin Condições de Pagamento (Fatia 1): busca as condições (READ-ONLY,
 * lista + detalhe, LOGOUT garantido no Python) e guarda-as. Corre no worker (com
 * docker socket). Serializado por empresa (mesma chave das outras sincronizações).
 */
class SyncPingwinPaymentConditionsJob implements ShouldQueue
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
        Log::info('[PingWin Condições de Pagamento] Job iniciado', ['company_id' => $this->companyId]);
        try {
            $count = $pingwin->syncPaymentConditions($this->companyId);
        } catch (\Throwable $e) {
            Log::warning('[PingWin Condições de Pagamento] Falhou', ['company_id' => $this->companyId, 'error' => $e->getMessage()]);
            $alerts->createSystemAlert(
                companyId: $this->companyId,
                type: 'warning',
                title: 'Falha ao sincronizar condições de pagamento',
                message: 'Não foi possível obter as condições de pagamento do PingWin. Tenta novamente.',
                severity: 'high',
                detailPath: '/restauracao/condicoes-pagamento',
            );

            return;
        }

        $alerts->createSystemAlert(
            companyId: $this->companyId,
            type: 'opportunity',
            title: 'Condições de pagamento atualizadas',
            message: "Condições de pagamento do PingWin atualizadas ({$count}).",
            severity: 'low',
            detailPath: '/restauracao/condicoes-pagamento',
        );
        Log::info('[PingWin Condições de Pagamento] Job concluído', ['company_id' => $this->companyId, 'count' => $count]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('[PingWin Condições de Pagamento] Job falhou', ['company_id' => $this->companyId, 'error' => $e->getMessage()]);
        app(AlertService::class)->createSystemAlert(
            companyId: $this->companyId,
            type: 'warning',
            title: 'Falha ao sincronizar condições de pagamento',
            message: 'Não foi possível obter as condições de pagamento do PingWin. Tenta novamente.',
            severity: 'high',
            detailPath: '/restauracao/condicoes-pagamento',
        );
    }
}
