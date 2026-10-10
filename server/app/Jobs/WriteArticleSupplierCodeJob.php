<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\OcrInvoiceLine;
use App\Models\PingwinCatalogItem;
use App\Models\PingwinCatalogWrite;
use App\Models\PingwinSupplierPrice;
use App\Services\OcrLineArticleService;
use App\Services\PingwinService;
use App\Services\SupplierArticleMapService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — F2b ⚠️ ESCRITA no PingWin: grava o CÓDIGO DO FORNECEDOR no artigo (lista de
 * fornecedores do artigo, tbsupprice), pelo fluxo de edição de artigos (updateProduct com
 * supplier_prices_changes; auditoria em pingwin_catalog_writes). Confirmação por RELEITURA: o
 * espelho das linhas de fornecedor (refeito pela releitura) tem de ter o código.
 *
 *  1. lê o artigo VIVO (linhas de fornecedor + tabelas por fornecedor);
 *  2. já tem o código → ok; tem OUTRO código e não foi pedido substituir → conflito;
 *  3. tem linha deste fornecedor → EDIT do sup_product_code; senão → NEW na tabela do fornecedor;
 *  4. releitura → ok / erro.
 * Serializado com as outras escritas de artigos da empresa (mesmo lock). tries=1.
 */
class WriteArticleSupplierCodeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 400;      // leitura viva + escrita (docker exec ×2)
    public int $maxExceptions = 1;

    public function __construct(public int $companyId, public int $lineId, public bool $replace = false) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("pingwin-sync:{$this->companyId}"))->releaseAfter(15)->expireAfter(600)];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    public function handle(PingwinService $pingwin, OcrLineArticleService $lines): void
    {
        $line = OcrInvoiceLine::where('company_id', $this->companyId)->find($this->lineId);
        if (! $line || $line->supplier_code_status !== 'pendente' || ! $line->article_id) {
            return;
        }
        $inv = $line->invoice;
        $article = PingwinCatalogItem::where('company_id', $this->companyId)->find($line->article_id);
        $supplier = $lines->supplier($inv);
        $code = trim((string) $line->supplier_code);
        if (! $article || ! $article->pingwin_id || ! $supplier || $code === '') {
            $this->done($line, 'erro', 'Artigo, fornecedor ou código em falta.');

            return;
        }
        $norm = SupplierArticleMapService::normCode($code);

        try {
            // 1. Leitura viva (também refaz o espelho das linhas de fornecedor do artigo).
            $p = $pingwin->readProduct($this->companyId, (string) $article->pingwin_id);
            $mine = collect($p['supplier_prices'] ?? [])->filter(fn ($l) => (string) ($l['supplier']['pingwin_id'] ?? '') === (string) $supplier->pingwin_id);
            if ($mine->contains(fn ($l) => SupplierArticleMapService::normCode($l['sup_product_code'] ?? '') === $norm)) {
                $this->done($line, 'ok', null);

                return;
            }
            $withCode = $mine->first(fn ($l) => trim((string) ($l['sup_product_code'] ?? '')) !== '');
            if ($withCode && ! $this->replace) {
                $this->done($line, 'conflito', "O artigo já tem o código «{$withCode['sup_product_code']}» para este fornecedor.");

                return;
            }

            // 3. Mudança: editar a linha existente deste fornecedor, ou criar na tabela dele.
            $target = $withCode ?? $mine->first();
            if ($target) {
                $changes = ['update' => [['line_pingwin_id' => $target['line_pingwin_id'], 'sup_product_code' => $code]]];
            } else {
                $table = collect($p['supplier_tables'] ?? [])->first(fn ($t) => (string) ($t['supplier_id'] ?? '') === (string) $supplier->pingwin_id);
                if (! $table || ! $table['table_id']) {
                    $this->done($line, 'erro', 'O fornecedor não tem tabela de preços no PingWin.');

                    return;
                }
                $changes = ['create' => [[
                    'supprice_header_id' => $table['table_id'],
                    'supplier_id'        => $supplier->id,
                    'unit_id'            => $article->default_purchase_unit_id ?: $article->base_unit_id,
                    'price_cents'        => $line->unit_price !== null ? (int) round(((float) $line->unit_price) * 100) : null,
                    'sup_product_code'   => $code,
                ]]];
            }

            $write = PingwinCatalogWrite::create([
                'company_id' => $this->companyId, 'user_id' => $line->linked_by, 'action' => 'editar',
                'catalog_item_id' => $article->id, 'code' => $article->code,
                'description' => $article->description ?? ('#' . $article->id), 'payload' => [],
                'supplier_prices_changes' => $changes, 'status' => 'a_editar',
            ]);
            $line->update(['supplier_code_write_id' => $write->id]);
            $res = $pingwin->updateProduct($this->companyId, (string) $article->pingwin_id, [], null, null, $changes);
            if (! ($res['ok'] ?? false)) {
                $write->update(['status' => 'erro', 'error_message' => mb_substr((string) ($res['error'] ?? 'Gravação recusada.'), 0, 800), 'finished_at' => now()]);
                $this->done($line, 'erro', (string) ($res['error'] ?? 'O PingWin recusou a gravação.'));

                return;
            }

            // 4. Confirmação: o espelho foi refeito com a releitura do servidor.
            $confirmed = PingwinSupplierPrice::where('company_id', $this->companyId)->where('is_active', true)
                ->where('product_pingwin_id', $article->pingwin_id)->where('supplier_pingwin_id', $supplier->pingwin_id)
                ->get(['sup_product_code'])->contains(fn ($l) => SupplierArticleMapService::normCode($l->sup_product_code) === $norm);
            $write->update(['status' => $confirmed ? 'ok' : 'erro', 'pingwin_id' => (string) $article->pingwin_id, 'finished_at' => now(),
                'error_message' => $confirmed ? null : 'A releitura não mostra o código do fornecedor.']);
            $this->done($line, $confirmed ? 'ok' : 'erro', $confirmed ? null : 'A releitura do artigo não mostra o código do fornecedor.');
        } catch (\Throwable $e) {
            Log::warning('[OCR Artigo] código do fornecedor falhou', ['line_id' => $this->lineId, 'error' => $e->getMessage()]);
            $this->done($line, 'erro', $e->getMessage());
        }
    }

    public function failed(\Throwable $e): void
    {
        OcrInvoiceLine::whereKey($this->lineId)->where('supplier_code_status', 'pendente')
            ->update(['supplier_code_status' => 'erro', 'supplier_code_error' => mb_substr($e->getMessage(), 0, 500)]);
    }

    private function done(OcrInvoiceLine $line, string $status, ?string $error): void
    {
        $line->update(['supplier_code_status' => $status, 'supplier_code_error' => $error ? mb_substr($error, 0, 500) : null]);
    }
}
