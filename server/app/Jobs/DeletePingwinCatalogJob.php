<?php

namespace App\Jobs;

use App\Models\PingwinCatalogItem;
use App\Models\PingwinCatalogWrite;
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
 * XPLENDOR — ⚠️ ESCRITA no PingWin: APAGAR (DELETE definitivo) um artigo. Corre no
 * worker (docker socket). O Python confirma por releitura VAZIA; SÓ se
 * deleted_confirmed=true é que marcamos o ESPELHO como soft-deleted (is_active=false)
 * — histórico local, NUNCA reactiva no PingWin (apagado é apagado). Serializado por
 * empresa. tries=1 → nunca repete cegamente.
 */
class DeletePingwinCatalogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;   // escrita destrutiva: NÃO repetir cegamente
    public int $timeout = 180;

    public function __construct(public int $companyId, public int $writeId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("pingwin-sync:{$this->companyId}"))->releaseAfter(15)->expireAfter(600)];
    }

    public function handle(PingwinService $pingwin, AlertService $alerts): void
    {
        $write = PingwinCatalogWrite::where('company_id', $this->companyId)->find($this->writeId);
        if (! $write || $write->status !== 'a_anular') {
            return; // já processada ou inexistente (idempotência básica)
        }

        $item = $write->catalog_item_id
            ? PingwinCatalogItem::where('company_id', $this->companyId)->find($write->catalog_item_id)
            : null;
        if (! $item || ! $item->pingwin_id) {
            $this->markError($write, 'Artigo local não encontrado ou sem pingwin_id — não há o que apagar.', $alerts);

            return;
        }

        Log::info('[PingWin Anular Artigo] Job iniciado', ['company_id' => $this->companyId, 'write_id' => $this->writeId, 'code' => $item->code]);

        try {
            $result = $pingwin->deleteProduct($this->companyId, (string) $item->pingwin_id, (string) ($item->code ?? ''));
        } catch (\Throwable $e) {
            $this->markError($write, $e->getMessage(), $alerts);
            Log::warning('[PingWin Anular Artigo] Falhou', ['write_id' => $this->writeId, 'error' => $e->getMessage()]);

            return;
        }

        // ⚠️ Mensagem de sucesso ≠ apagado. Só deleted_confirmed=true (releitura VAZIA) vale.
        if (! ($result['deleted_confirmed'] ?? false)) {
            $this->markError($write, 'O artigo AINDA aparece no PingWin após o DELETE (releitura pelo code não veio vazia).', $alerts);
            Log::error('[PingWin Anular Artigo] NÃO confirmado', ['write_id' => $this->writeId, 'code' => $item->code]);

            return;
        }

        // Só AGORA o espelho: soft-delete (histórico local; NUNCA reactiva no PingWin).
        $item->update(['is_active' => false, 'synced_at' => now()]);

        $write->update([
            'status'      => 'ok',
            'pingwin_id'  => (string) $item->pingwin_id,
            'finished_at' => now(),
        ]);

        $alerts->createSystemAlert(
            companyId: $this->companyId,
            type: 'opportunity',
            title: 'Artigo apagado no PingWin',
            message: 'O artigo «' . $write->description . '» (code ' . ($item->code ?? '?') . ') foi apagado no PingWin.',
            severity: 'low',
            detailPath: '/restauracao/artigos',
        );
        Log::info('[PingWin Anular Artigo] Concluído', ['write_id' => $this->writeId, 'code' => $item->code]);
    }

    private function markError(PingwinCatalogWrite $write, string $message, AlertService $alerts): void
    {
        $write->update(['status' => 'erro', 'error_message' => mb_substr($message, 0, 800), 'finished_at' => now()]);
        $alerts->createSystemAlert(
            companyId: $this->companyId,
            type: 'warning',
            title: 'Falha ao apagar o artigo',
            message: 'Não foi possível apagar o artigo «' . $write->description . '» no PingWin: ' . mb_substr($message, 0, 200),
            severity: 'high',
            detailPath: '/restauracao/artigos',
        );
    }

    /**
     * ⚠️ Segurança: se o job rebentar FORA do try (timeout do docker exec, worker morto),
     * a linha NÃO pode ficar presa em 'a_anular' (o polling da UI giraria para sempre).
     */
    public function failed(\Throwable $e): void
    {
        $write = PingwinCatalogWrite::find($this->writeId);
        if ($write && $write->status === 'a_anular') {
            $write->update(['status' => 'erro', 'error_message' => mb_substr($e->getMessage(), 0, 800), 'finished_at' => now()]);
        }
        Log::error('[PingWin Anular Artigo] Job falhou', ['write_id' => $this->writeId, 'error' => $e->getMessage()]);
    }
}
