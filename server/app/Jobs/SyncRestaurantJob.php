<?php

namespace App\Jobs;

use App\Services\AlertService;
use App\Services\CoverManagerService;
use App\Services\PingwinService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — Etapa 4: sincronização COMPLETA do restaurante numa só passagem —
 * PingWin (vendas) + CoverManager (reservas) de TODAS as lojas, para uma data.
 * Orquestra os SERVIÇOS que os jobs individuais já usam (não recria a lógica) e
 * emite UMA notificação no FIM (não uma por integração). Serializado por empresa
 * (mesma chave "pingwin-sync:{companyId}" — nunca duas sincronizações juntas).
 */
class SyncRestaurantJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 420; // PingWin (180s) + CoverManager + margem.

    public function __construct(
        public int $companyId,
        public ?string $date = null,
    ) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("pingwin-sync:{$this->companyId}"))->expireAfter(900)];
    }

    public function handle(PingwinService $pingwin, CoverManagerService $cover, AlertService $alerts): void
    {
        $date = $this->date ?: now()->subDay()->toDateString();
        Log::info('[Restaurant Sync] Job iniciado', ['company_id' => $this->companyId, 'date' => $date]);

        $problems = [];

        // 1) PingWin (vendas) — todas as lojas cadastradas (o relatório traz todas).
        try {
            $pingwin->sync($this->companyId, $date);
        } catch (\Throwable $e) {
            Log::warning('[Restaurant Sync] PingWin falhou', ['company_id' => $this->companyId, 'error' => $e->getMessage()]);
            $problems[] = 'vendas (PingWin): ' . mb_substr($e->getMessage(), 0, 150);
        }

        // 2) CoverManager (reservas) — todas as lojas com slug + token resolvível.
        //    Só corre se a empresa tem CoverManager (ou alguma loja com override).
        $companyToken = $cover->companyToken($this->companyId);
        if ($cover->syncableLocations($this->companyId, $companyToken)->isNotEmpty()) {
            try {
                $result = $cover->sync($this->companyId, $date);
                if (! empty($result['failed'])) {
                    $problems[] = 'reservas (CoverManager): ' . implode(', ', $result['failed']);
                }
            } catch (\Throwable $e) {
                Log::warning('[Restaurant Sync] CoverManager falhou', ['company_id' => $this->companyId, 'error' => $e->getMessage()]);
                $problems[] = 'reservas (CoverManager): ' . mb_substr($e->getMessage(), 0, 150);
            }
        }

        // 3) UMA notificação no fim de tudo.
        if (empty($problems)) {
            $alerts->createSystemAlert(
                companyId: $this->companyId,
                type: 'opportunity',
                title: 'Dados atualizados',
                message: "Vendas e reservas de {$date} atualizadas.",
                severity: 'low',
                detailPath: '/restauracao',
            );
        } else {
            $alerts->createSystemAlert(
                companyId: $this->companyId,
                type: 'warning',
                title: 'Dados atualizados com problemas',
                message: 'Nem tudo foi atualizado — ' . implode(' | ', $problems),
                severity: 'high',
                detailPath: '/restauracao',
            );
        }

        Log::info('[Restaurant Sync] Job concluído', ['company_id' => $this->companyId, 'problems' => $problems]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('[Restaurant Sync] Job falhou', ['company_id' => $this->companyId, 'error' => $e->getMessage()]);
        app(AlertService::class)->createSystemAlert(
            companyId: $this->companyId,
            type: 'warning',
            title: 'Falha ao atualizar dados',
            message: 'Não foi possível atualizar os dados de restauração. Tenta novamente.',
            severity: 'high',
            detailPath: '/restauracao',
        );
    }
}
