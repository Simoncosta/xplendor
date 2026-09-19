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
 * XPLENDOR — Sincronização PingWin em FILA (a busca é lenta → não trava a tela).
 * Reutiliza PingwinService::sync() (login → relatório → LOGOUT garantido no
 * Python; nada é recriado aqui). No fim, notifica no sino (AlertService).
 *
 * ⚠️ SERIALIZADO por empresa (WithoutOverlapping "pingwin-sync:{companyId}"):
 * nunca dois jobs da mesma empresa em simultâneo — protege contra sessões
 * PingWin concorrentes.
 */
class SyncPingwinJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // A busca PingWin tem timeout de 180s no serviço; damos margem. Sem retries
    // automáticos (uma sessão PingWin de cada vez; o utilizador re-dispara).
    public int $tries = 1;
    public int $timeout = 300;

    public function __construct(
        public int $companyId,
        public ?string $date = null,
    ) {}

    public function middleware(): array
    {
        // Serializa por empresa: o 2.º job da mesma empresa não corre em paralelo.
        return [
            (new WithoutOverlapping("pingwin-sync:{$this->companyId}"))->expireAfter(600),
        ];
    }

    public function handle(PingwinService $pingwin, AlertService $alerts): void
    {
        Log::info('[PingWin Sync] Job iniciado', ['company_id' => $this->companyId, 'date' => $this->date]);

        try {
            // Reutiliza o ciclo com LOGOUT garantido. Lança com o MOTIVO REAL do
            // relatório (trigger_report/download → run.py → PingwinService::sync).
            $pingwin->sync($this->companyId, $this->date);
        } catch (\Throwable $e) {
            // ⚠️ NÃO esconder a causa: o erro real do relatório vai ao log E à
            // notificação (ex.: report_id errado, HTTP 500 no trigger, XLS vazio…).
            Log::error('[PingWin Sync] Falha no relatório', [
                'company_id' => $this->companyId, 'date' => $this->date, 'error' => $e->getMessage(),
            ]);
            $reason = mb_substr($e->getMessage(), 0, 300);
            $alerts->createSystemAlert(
                companyId: $this->companyId,
                type: 'warning',
                title: 'Falha ao atualizar vendas',
                message: "Não foi possível atualizar as vendas do PingWin. Motivo: {$reason}",
                severity: 'high',
                detailPath: '/restauracao',
            );

            return; // já notificado com o motivo real → evita a notificação genérica do failed().
        }

        $quando = $this->date ? "de {$this->date}" : 'de ontem';
        $alerts->createSystemAlert(
            companyId: $this->companyId,
            type: 'opportunity',
            title: 'Dados de vendas atualizados',
            message: "Os dados de vendas {$quando} foram atualizados a partir do PingWin.",
            severity: 'low',
            detailPath: '/restauracao',
        );

        Log::info('[PingWin Sync] Job concluído', ['company_id' => $this->companyId]);
    }

    /**
     * Falhou (após esgotar as tentativas): notifica claramente no sino. Sem DI
     * aqui — resolvemos o AlertService do container.
     */
    public function failed(\Throwable $e): void
    {
        Log::error('[PingWin Sync] Job falhou', [
            'company_id' => $this->companyId,
            'date' => $this->date,
            'error' => $e->getMessage(),
        ]);

        app(AlertService::class)->createSystemAlert(
            companyId: $this->companyId,
            type: 'warning',
            title: 'Falha ao atualizar vendas',
            message: 'Não foi possível atualizar os dados de vendas do PingWin. Verifica a ligação e tenta novamente.',
            severity: 'high',
            detailPath: '/restauracao',
        );
    }
}
