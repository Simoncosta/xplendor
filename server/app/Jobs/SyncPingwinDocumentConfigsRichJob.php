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
 * XPLENDOR — PingWin Documentos LEITURA RICA (Fase D0): busca a config completa de cada
 * documento (READ-ONLY: maindataset + options + 14 filhas + additionalfields) e guarda-a.
 * Corre no worker (docker socket). Serializado por empresa (mesma chave das outras syncs).
 * timeout generoso (enriquece N documentos, cada um um GET grande).
 */
class SyncPingwinDocumentConfigsRichJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 900;   // leitura rica de todos os documentos → payloads grandes

    public function __construct(public int $companyId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("pingwin-sync:{$this->companyId}"))->releaseAfter(15)->expireAfter(1800)];
    }

    public function handle(PingwinService $pingwin, AlertService $alerts): void
    {
        Log::info('[PingWin Documentos Rico] Job iniciado', ['company_id' => $this->companyId]);
        try {
            $count = $pingwin->syncDocumentConfigsRich($this->companyId);
        } catch (\Throwable $e) {
            Log::warning('[PingWin Documentos Rico] Falhou', ['company_id' => $this->companyId, 'error' => $e->getMessage()]);
            $alerts->createSystemAlert(
                companyId: $this->companyId,
                type: 'warning',
                title: 'Falha ao sincronizar documentos (detalhe)',
                message: 'Não foi possível obter a config completa dos documentos do PingWin. Tenta novamente.',
                severity: 'high',
                detailPath: '/restauracao/documentos',
            );

            return;
        }

        $alerts->createSystemAlert(
            companyId: $this->companyId,
            type: 'opportunity',
            title: 'Documentos (detalhe) atualizados',
            message: "Config completa dos documentos do PingWin atualizada ({$count}).",
            severity: 'low',
            detailPath: '/restauracao/documentos',
        );
        Log::info('[PingWin Documentos Rico] Job concluído', ['company_id' => $this->companyId, 'count' => $count]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('[PingWin Documentos Rico] Job falhou', ['company_id' => $this->companyId, 'error' => $e->getMessage()]);
        app(AlertService::class)->createSystemAlert(
            companyId: $this->companyId,
            type: 'warning',
            title: 'Falha ao sincronizar documentos (detalhe)',
            message: 'Não foi possível obter a config completa dos documentos do PingWin. Tenta novamente.',
            severity: 'high',
            detailPath: '/restauracao/documentos',
        );
    }
}
