<?php

namespace App\Jobs;

use App\Models\PingwinDocconfigWrite;
use App\Models\PingwinDocumentConfig;
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
 * XPLENDOR — ⚠️ ESCRITA no PingWin: ANULAR um documento ATIVO (Fase D4, soft-delete). Corre
 * no worker. O Python confirma por releitura (sai do STATE 0, entra no STATE 1). SÓ se
 * voided_confirmed=true é que marcamos deleted=true no ESPELHO. tries=1.
 */
class VoidPingwinDocumentConfigJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 180;

    public function __construct(public int $companyId, public int $writeId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("pingwin-sync:{$this->companyId}"))->releaseAfter(15)->expireAfter(600)];
    }

    public function handle(PingwinService $pingwin, AlertService $alerts): void
    {
        $write = PingwinDocconfigWrite::where('company_id', $this->companyId)->find($this->writeId);
        if (! $write || $write->status !== 'a_criar') {
            return;
        }

        Log::info('[PingWin Anular Documento] Job iniciado', ['company_id' => $this->companyId, 'write_id' => $this->writeId, 'doc' => $write->docconfig_id]);

        try {
            $result = $pingwin->voidDocumentConfig($this->companyId, (string) $write->docconfig_id);
        } catch (\Throwable $e) {
            $this->markError($write, $e->getMessage(), $alerts);
            Log::warning('[PingWin Anular Documento] Falhou', ['write_id' => $this->writeId, 'error' => $e->getMessage()]);

            return;
        }

        // ⚠️ 200 ≠ anulado. Só voided_confirmed=true (releitura STATE 1) vale.
        if (! ($result['voided_confirmed'] ?? false)) {
            $this->markError($write, $result['error'] ?? 'A anulação não foi confirmada por releitura.', $alerts);
            Log::error('[PingWin Anular Documento] NÃO confirmado', ['write_id' => $this->writeId, 'result' => $result]);

            return;
        }

        PingwinDocumentConfig::where('company_id', $this->companyId)
            ->where('external_id', (string) $write->docconfig_id)
            ->update(['deleted' => true, 'synced_at' => now()]);

        $write->update(['status' => 'ok', 'finished_at' => now()]);
        $alerts->createSystemAlert(
            companyId: $this->companyId,
            type: 'opportunity',
            title: 'Documento anulado no PingWin',
            message: 'O documento «' . $write->description . '» foi anulado no PingWin.',
            severity: 'low',
            detailPath: '/restauracao/documentos',
        );
        Log::info('[PingWin Anular Documento] Concluído', ['write_id' => $this->writeId, 'doc' => $write->docconfig_id]);
    }

    private function markError(PingwinDocconfigWrite $write, string $message, AlertService $alerts): void
    {
        $write->update(['status' => 'erro', 'error_message' => mb_substr($message, 0, 800), 'finished_at' => now()]);
        $alerts->createSystemAlert(
            companyId: $this->companyId,
            type: 'warning',
            title: 'Falha ao anular o documento',
            message: 'Não foi possível anular o documento «' . $write->description . '» no PingWin: ' . mb_substr($message, 0, 200),
            severity: 'high',
            detailPath: '/restauracao/documentos',
        );
    }

    public function failed(\Throwable $e): void
    {
        $write = PingwinDocconfigWrite::find($this->writeId);
        if ($write && $write->status === 'a_criar') {
            $write->update(['status' => 'erro', 'error_message' => mb_substr($e->getMessage(), 0, 800), 'finished_at' => now()]);
        }
        Log::error('[PingWin Anular Documento] Job falhou', ['write_id' => $this->writeId, 'error' => $e->getMessage()]);
    }
}
