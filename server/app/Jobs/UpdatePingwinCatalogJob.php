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
 * XPLENDOR — ⚠️ ESCRITA no PingWin: EDITAR um artigo (inclui mudar o Estado). Corre no
 * worker (docker socket). O Python devolve a confirmação AUTORITATIVA (re-OPEN pós-SAVE);
 * SÓ se os campos alterados baterem certo é que atualizamos o ESPELHO (com os valores
 * confirmados pelo servidor). Serializado por empresa. tries=1 → nunca repete cegamente.
 */
class UpdatePingwinCatalogJob implements ShouldQueue
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
        $write = PingwinCatalogWrite::where('company_id', $this->companyId)->find($this->writeId);
        if (! $write || $write->status !== 'a_editar') {
            return;
        }

        $item = $write->catalog_item_id
            ? PingwinCatalogItem::where('company_id', $this->companyId)->find($write->catalog_item_id)
            : null;
        if (! $item || ! $item->pingwin_id) {
            $this->markError($write, 'Artigo local não encontrado ou sem pingwin_id.', $alerts);

            return;
        }

        $changes = (array) ($write->payload ?? []);
        $saleprice = $write->saleprice_cents !== null ? PingwinService::centsToDecimalString($write->saleprice_cents) : null;
        $purchaseprice = $write->purchaseprice_cents !== null ? PingwinService::centsToDecimalString($write->purchaseprice_cents) : null;
        $supplierChanges = $write->supplier_prices_changes ?: null; // C2: null/vazio → editar idêntico

        try {
            $result = $pingwin->updateProduct($this->companyId, (string) $item->pingwin_id, $changes, $saleprice, $purchaseprice, $supplierChanges);
        } catch (\Throwable $e) {
            $this->markError($write, $e->getMessage(), $alerts);
            Log::warning('[PingWin Editar Artigo] Falhou', ['write_id' => $this->writeId, 'error' => $e->getMessage()]);

            return;
        }

        // ⚠️ Confirmação autoritativa: os campos alterados TÊM de bater certo com a releitura.
        $confirm = $result['confirm'] ?? [];

        if (array_key_exists('status', $changes) && (int) ($confirm['status'] ?? -1) !== (int) $changes['status']) {
            $this->markError($write, 'O Estado (status) não ficou como enviado (releitura ≠ pedido).', $alerts);

            return;
        }
        if ($write->saleprice_cents !== null
            && PingwinService::decimalToCents((string) ($confirm['saleprice'] ?? '0')) !== (int) $write->saleprice_cents) {
            $this->markError($write, 'O preço de venda não ficou como enviado (releitura ≠ pedido).', $alerts);

            return;
        }

        // Só AGORA o espelho — com os valores CONFIRMADOS pelo servidor (não os enviados).
        $this->updateMirror($item, $changes, $confirm);

        $write->update(['status' => 'ok', 'pingwin_id' => (string) $item->pingwin_id, 'finished_at' => now()]);
        $alerts->createSystemAlert(
            companyId: $this->companyId,
            type: 'opportunity',
            title: 'Artigo editado no PingWin',
            message: 'O artigo «' . $write->description . '» foi editado no PingWin.',
            severity: 'low',
            detailPath: '/restauracao/artigos',
        );
        Log::info('[PingWin Editar Artigo] Concluído', ['write_id' => $this->writeId, 'code' => $item->code]);
    }

    /** Atualiza o espelho com os valores CONFIRMADOS pela releitura autoritativa. */
    private function updateMirror(PingwinCatalogItem $item, array $changes, array $confirm): void
    {
        $data = ['synced_at' => now()];
        if (array_key_exists('status', $changes)) {
            $data['status_id'] = (string) ($confirm['status'] ?? $changes['status']);
        }
        if (array_key_exists('description', $changes)) {
            $data['description'] = (string) ($confirm['description'] ?? $item->description);
        }
        foreach (['family_id', 'product_type', 'taxgroup_id', 'stockconfig_id', 'printzone_id', 'base_unit_id'] as $k) {
            if (array_key_exists($k, $changes)) {
                $col = $k === 'product_type' ? 'product_type_id' : $k;
                $data[$col] = $changes[$k];
            }
        }
        foreach (['forsale', 'forpurchase', 'forproduction'] as $k) {
            if (array_key_exists($k, $changes)) {
                $data[$k] = (bool) $changes[$k];
            }
        }
        if (isset($confirm['saleprice'])) {
            $data['saleprice_cents'] = PingwinService::decimalToCents((string) $confirm['saleprice']);
        }
        if (isset($confirm['purchaseprice'])) {
            $data['purchaseprice_cents'] = PingwinService::decimalToCents((string) $confirm['purchaseprice']);
        }
        $item->update($data);
    }

    private function markError(PingwinCatalogWrite $write, string $message, AlertService $alerts): void
    {
        $write->update(['status' => 'erro', 'error_message' => mb_substr($message, 0, 800), 'finished_at' => now()]);
        $alerts->createSystemAlert(
            companyId: $this->companyId,
            type: 'warning',
            title: 'Falha ao editar o artigo',
            message: 'Não foi possível editar o artigo «' . $write->description . '» no PingWin: ' . mb_substr($message, 0, 200),
            severity: 'high',
            detailPath: '/restauracao/artigos',
        );
    }

    /** ⚠️ Nunca deixar 'a_editar' preso se o job rebentar fora do try. */
    public function failed(\Throwable $e): void
    {
        $write = PingwinCatalogWrite::find($this->writeId);
        if ($write && $write->status === 'a_editar') {
            $write->update(['status' => 'erro', 'error_message' => mb_substr($e->getMessage(), 0, 800), 'finished_at' => now()]);
        }
        Log::error('[PingWin Editar Artigo] Job falhou', ['write_id' => $this->writeId, 'error' => $e->getMessage()]);
    }
}
