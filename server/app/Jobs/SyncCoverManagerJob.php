<?php

namespace App\Jobs;

use App\Services\AlertService;
use App\Services\CoverManagerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — CoverManager: sincronização das reservas (agregado por turno) em
 * fila. HTTP leve; uma loja a falhar não aborta as outras (tratado no serviço).
 * Notifica no sino com o resultado. Serializado por empresa.
 */
class SyncCoverManagerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 180;

    public function __construct(
        public int $companyId,
        public string $date,
    ) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("covermanager-sync:{$this->companyId}"))->expireAfter(300)];
    }

    public function handle(CoverManagerService $cover, AlertService $alerts): void
    {
        Log::info('[CoverManager Sync] Job iniciado', ['company_id' => $this->companyId, 'date' => $this->date]);

        $result = $cover->sync($this->companyId, $this->date);

        if ($result['synced'] > 0 && empty($result['failed'])) {
            $alerts->createSystemAlert(
                companyId: $this->companyId,
                type: 'opportunity',
                title: 'Reservas atualizadas',
                message: "As reservas de {$this->date} foram atualizadas ({$result['synced']} loja(s)).",
                severity: 'low',
                detailPath: '/restauracao',
            );
        } else {
            $falhas = empty($result['failed']) ? 'sem lojas com CoverManager configurado' : implode(', ', $result['failed']);
            $alerts->createSystemAlert(
                companyId: $this->companyId,
                type: 'warning',
                title: 'Reservas — atualização parcial',
                message: "Nem todas as lojas foram atualizadas ({$falhas}).",
                severity: 'medium',
                detailPath: '/restauracao',
            );
        }

        Log::info('[CoverManager Sync] Job concluído', ['company_id' => $this->companyId, 'result' => $result]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('[CoverManager Sync] Job falhou', ['company_id' => $this->companyId, 'error' => $e->getMessage()]);
        app(AlertService::class)->createSystemAlert(
            companyId: $this->companyId,
            type: 'warning',
            title: 'Falha ao atualizar reservas',
            message: 'Não foi possível atualizar as reservas do CoverManager. Tenta novamente.',
            severity: 'high',
            detailPath: '/restauracao',
        );
    }
}
