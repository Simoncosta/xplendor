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
 * XPLENDOR — ⚠️ ESCRITA no PingWin: CRIAR um artigo. Corre no worker (docker socket).
 * A confirmação do utilizador foi dada a montante (controller exigiu confirm). O
 * Python confirma por releitura; SÓ se persisted=true é que atualizamos o ESPELHO
 * local (pingwin_catalog_items), com o raw do servidor e os preços em cêntimos.
 * Serializado por empresa. tries=1 → nunca repete cegamente (não duplica no PingWin).
 */
class CreatePingwinCatalogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;   // escrita: NÃO repetir cegamente
    public int $timeout = 180;

    public function __construct(public int $companyId, public int $writeId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("pingwin-sync:{$this->companyId}"))->releaseAfter(15)->expireAfter(600)];
    }

    public function handle(PingwinService $pingwin, AlertService $alerts): void
    {
        $write = PingwinCatalogWrite::where('company_id', $this->companyId)->find($this->writeId);
        if (! $write || $write->status !== 'a_criar') {
            return; // já processada ou inexistente (idempotência básica)
        }

        Log::info('[PingWin Criar Artigo] Job iniciado', ['company_id' => $this->companyId, 'write_id' => $this->writeId]);

        // Fronteira: cêntimos (BD) → decimal string (PingWin). Só envia se houver preço.
        $saleprice = $write->saleprice_cents !== null ? PingwinService::centsToDecimalString($write->saleprice_cents) : null;
        $purchaseprice = $write->purchaseprice_cents !== null ? PingwinService::centsToDecimalString($write->purchaseprice_cents) : null;

        try {
            $result = $pingwin->createProduct($this->companyId, (array) ($write->payload ?? []), $saleprice, $purchaseprice);
        } catch (\Throwable $e) {
            $this->markError($write, $e->getMessage(), $alerts);
            Log::warning('[PingWin Criar Artigo] Falhou', ['write_id' => $this->writeId, 'error' => $e->getMessage()]);

            return;
        }

        // ⚠️ Mensagem de sucesso ≠ gravado. Só persisted=true (confirmado por releitura) vale.
        if (! ($result['persisted'] ?? false)) {
            $this->markError($write, 'O artigo não foi confirmado no PingWin após o SAVE (releitura pelo code não o encontrou).', $alerts);
            Log::error('[PingWin Criar Artigo] NÃO persistiu', ['write_id' => $this->writeId, 'code' => $result['code'] ?? null]);

            return;
        }

        // Só AGORA se toca no espelho local — com o raw do servidor e preços em cêntimos.
        $this->updateMirror($result, $write);

        $write->update([
            'status'      => 'ok',
            'code'        => (string) ($result['code'] ?? $write->code),
            'pingwin_id'  => (string) ($result['pingwin_id'] ?? ''),
            'finished_at' => now(),
        ]);

        $alerts->createSystemAlert(
            companyId: $this->companyId,
            type: 'opportunity',
            title: 'Artigo criado no PingWin',
            message: 'O artigo «' . $write->description . '» (code ' . ($result['code'] ?? '?') . ') foi criado no PingWin.',
            severity: 'low',
            detailPath: '/restauracao/artigos',
        );
        Log::info('[PingWin Criar Artigo] Concluído', ['write_id' => $this->writeId, 'code' => $result['code'] ?? null]);
    }

    /** Atualiza (upsert) o espelho pingwin_catalog_items só após persisted=true. */
    private function updateMirror(array $result, PingwinCatalogWrite $write): void
    {
        $pingwinId = (string) ($result['pingwin_id'] ?? '');
        if ($pingwinId === '') {
            return; // sem id do servidor não indexamos o espelho (a auditoria fica na write row)
        }
        $raw = $result['raw'] ?? [];
        $payload = (array) ($write->payload ?? []);

        PingwinCatalogItem::updateOrCreate(
            ['company_id' => $this->companyId, 'pingwin_id' => $pingwinId],
            [
                'code'                => (string) ($result['code'] ?? ($raw['code'] ?? '')),
                'description'         => (string) ($raw['description'] ?? $write->description),
                'family_id'           => $payload['family_id'] ?? null,
                'forsale'             => (bool) ($payload['forsale'] ?? false),
                'forpurchase'         => (bool) ($payload['forpurchase'] ?? false),
                'forproduction'       => (bool) ($payload['forproduction'] ?? false),
                'product_type_id'     => $payload['product_type'] ?? null,   // corpo PingWin: sem _id → coluna com _id
                'status_id'           => $payload['status'] ?? null,
                'taxgroup_id'         => $payload['taxgroup_id'] ?? null,
                'stockconfig_id'      => $payload['stockconfig_id'] ?? null,
                'printzone_id'        => $payload['printzone_id'] ?? null,
                'base_unit_id'        => $payload['base_unit_id'] ?? null,
                'saleprice_cents'     => $write->saleprice_cents,
                'purchaseprice_cents' => $write->purchaseprice_cents,
                'raw'                 => $raw,
                'is_active'           => true,
                'synced_at'           => now(),
            ]
        );
    }

    private function markError(PingwinCatalogWrite $write, string $message, AlertService $alerts): void
    {
        $write->update(['status' => 'erro', 'error_message' => mb_substr($message, 0, 800), 'finished_at' => now()]);
        $alerts->createSystemAlert(
            companyId: $this->companyId,
            type: 'warning',
            title: 'Falha ao criar o artigo',
            message: 'Não foi possível criar o artigo «' . $write->description . '» no PingWin: ' . mb_substr($message, 0, 200),
            severity: 'high',
            detailPath: '/restauracao/artigos',
        );
    }

    /**
     * ⚠️ Segurança: se o job rebentar FORA do try (timeout do docker exec, worker
     * morto, OOM), a linha NÃO pode ficar presa em 'a_criar' (o polling da UI giraria
     * para sempre). Estado preso = mentira silenciosa → marcar erro.
     */
    public function failed(\Throwable $e): void
    {
        $write = PingwinCatalogWrite::find($this->writeId);
        if ($write && $write->status === 'a_criar') {
            $write->update(['status' => 'erro', 'error_message' => mb_substr($e->getMessage(), 0, 800), 'finished_at' => now()]);
        }
        Log::error('[PingWin Criar Artigo] Job falhou', ['write_id' => $this->writeId, 'error' => $e->getMessage()]);
    }
}
