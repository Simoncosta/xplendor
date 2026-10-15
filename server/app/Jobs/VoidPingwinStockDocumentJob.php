<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PingwinStockWrite;
use App\Models\StockCount;
use App\Models\WasteBatch;
use App\Services\PingwinService;
use App\Services\Restaurant\StockDocuments;
use App\Services\Restaurant\StockSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * XPLENDOR — Fatia 7b (⚠️ ESCREVE NO PINGWIN): anula UMA contagem ou UM desperdício enviados pela
 * XPLENDOR (/service/document/<id>/header, estado 8003), por ação de um gestor, e relê o stock.
 */
class VoidPingwinStockDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 600;

    public function __construct(public int $writeId) {}

    public function middleware(): array
    {
        $w = PingwinStockWrite::find($this->writeId);

        return $w ? [(new WithoutOverlapping("pingwin-stockdoc:{$w->company_id}"))->dontRelease()->expireAfter(900)] : [];
    }

    public function handle(PingwinService $pingwin, StockSyncService $sync): void
    {
        $write = PingwinStockWrite::find($this->writeId);
        if (! $write || $write->status !== 'queued') {
            return;
        }
        $target = $write->payload['target'] ?? '';
        $model = $target === 'contagem' ? StockCount::where('company_id', $write->company_id)->find($write->payload['id'] ?? 0)
            : WasteBatch::where('company_id', $write->company_id)->find($write->payload['id'] ?? 0);
        if (! $model || $model->status !== 'enviada' || ! $model->pingwin_doc_id) {
            $write->update(['status' => 'failed', 'error' => 'O documento já não está enviado.', 'finished_at' => now()]);

            return;
        }
        $write->update(['status' => 'running', 'started_at' => now()]);
        try {
            $res = $pingwin->stockDocument($write->company_id, 'stockdoc_status', ['doc_id' => $model->pingwin_doc_id, 'status' => '8003']);
        } catch (\Throwable $e) {
            $write->update(['status' => 'failed', 'error' => WritePingwinCountJob::human($e->getMessage()), 'finished_at' => now()]);

            return;
        }
        if (! ($res['ok'] ?? false)) {
            $write->update(['status' => 'failed', 'error' => WritePingwinCountJob::human((string) ($res['error'] ?? 'O PingWin não anulou o documento.')), 'result' => $res, 'finished_at' => now()]);

            return;
        }
        $model->forceFill(['status' => 'anulada'] + ($target === 'contagem' ? ['voided_at' => now()] : []))->save();
        $products = $target === 'contagem' ? $model->lines()->whereNotNull('qty_base')->pluck('product_id')->all() : array_column((array) $model->lines, 'product_id');
        try {
            (new StockDocuments($write->company_id, $pingwin, $sync))->reread($products, $model->warehouse_id);
        } catch (\Throwable) {
            // a anulação ficou feita; a releitura repete-se de noite
        }
        $write->update(['status' => 'ok', 'result' => ['docstatus_id' => $res['docstatus_id'] ?? null], 'finished_at' => now()]);
    }

    public function failed(\Throwable $e): void
    {
        PingwinStockWrite::where('id', $this->writeId)->whereIn('status', ['queued', 'running'])->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300), 'finished_at' => now()]);
    }
}
