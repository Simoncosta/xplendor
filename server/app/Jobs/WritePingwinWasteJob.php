<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PingwinStockWrite;
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
 * XPLENDOR — Fatia 7b (⚠️ ESCREVE NO PINGWIN): envia UM documento de Desperdício (1100, Fechado) com as
 * linhas pendentes de um armazém, por ação de um gestor. Uma tentativa só. Confirma pela lista dos
 * documentos (Fechado e total) e relê o stock dos artigos.
 */
class WritePingwinWasteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 900;
    /** Um timeout falha o job logo (nunca fica reservado para voltar a correr depois do retry_after). */
    public bool $failOnTimeout = true;

    public function __construct(public int $writeId) {}

    public function middleware(): array
    {
        $w = PingwinStockWrite::find($this->writeId);

        return $w ? [(new WithoutOverlapping("pingwin-stockdoc:{$w->company_id}"))->dontRelease()->expireAfter(1200)] : [];
    }

    public function handle(PingwinService $pingwin, StockSyncService $sync): void
    {
        $write = PingwinStockWrite::find($this->writeId);
        if (! $write || $write->status !== 'queued') {
            return;
        }
        $batch = WasteBatch::where('company_id', $write->company_id)->find($write->payload['batch_id'] ?? 0);
        if (! $batch || $batch->status !== 'a_enviar') {
            $write->update(['status' => 'failed', 'error' => 'O desperdício já não está para enviar.', 'finished_at' => now()]);

            return;
        }
        $write->update(['status' => 'running', 'started_at' => now()]);
        $fail = function (string $msg, array $result = []) use ($write, $batch) {
            $batch->update(['status' => 'falhou', 'error_message' => $msg]);
            $batch->entries()->update(['batch_id' => null]);   // voltam a estar pendentes, para corrigir e reenviar à mão
            $write->update(['status' => 'failed', 'error' => $msg, 'result' => $result ?: null, 'finished_at' => now()]);
        };
        $lines = (array) $batch->lines;
        try {
            $res = $pingwin->stockDocument($batch->company_id, 'waste_write', [
                'warehouse_id' => $batch->warehouse_id, 'docstatus' => '8002',
                'header_obs' => sprintf('XPLENDOR · Desperdício · %d %s', $batch->entries()->count(), $batch->entries()->count() === 1 ? 'registo' : 'registos'),
                'lines' => array_map(fn ($l) => ['product_id' => $l['product_id'], 'code' => $l['code'], 'qnt' => $l['qty'], 'obs' => $l['obs'] ?? null, 'unit_id' => $l['unit_id'] ?? null], $lines),
            ], 900);
        } catch (\Throwable $e) {
            $fail(WritePingwinCountJob::human($e->getMessage()) . ' O documento não foi gravado no PingWin.');

            return;
        }
        if (! ($res['saved'] ?? false)) {
            $msg = WritePingwinCountJob::human((string) ($res['error'] ?? 'O PingWin não gravou o documento.'));
            $fail(str_contains($msg, 'gravado') ? $msg : $msg . ' O documento não foi gravado no PingWin.', array_intersect_key($res, array_flip(['pingwin_message', 'steps', 'unit_mismatch'])));

            return;
        }
        $batch->update(['status' => 'enviada', 'pingwin_doc_id' => $res['doc_id'] ?? null, 'pingwin_doc_number' => $res['doc_number'] ?? null, 'error_message' => null]);
        $result = ['doc_id' => $res['doc_id'] ?? null, 'doc_number' => $res['doc_number'] ?? null, 'steps' => $res['steps'] ?? []];
        try {
            $docs = new StockDocuments($batch->company_id, $pingwin, $sync);
            $doc = $docs->find((string) $res['doc_id'], (string) $batch->date, 'docs', 'Desperd');
            $result['listed'] = $doc ? ['status' => $doc['docstatus_id'] ?? null, 'total' => $doc['total'] ?? null, 'document' => $doc['document'] ?? null] : null;
            $batch->update(['pingwin_total' => $doc['total'] ?? ($res['total'] ?? null)]);
            $docs->reread(array_column($lines, 'product_id'), $batch->warehouse_id);
            $ok = $doc && (string) ($doc['docstatus_id'] ?? '') === '8002';
            $write->update(['status' => $ok ? 'ok' : 'mismatch', 'result' => $result, 'finished_at' => now(),
                'error' => $ok ? null : 'Gravado no PingWin, mas o documento não aparece Fechado na lista.']);
        } catch (\Throwable $e) {
            $write->update(['status' => 'mismatch', 'result' => $result, 'finished_at' => now(), 'error' => 'Gravado no PingWin, mas não foi possível confirmar: ' . mb_substr($e->getMessage(), 0, 200)]);
        }
    }

    public function failed(\Throwable $e): void
    {
        $w = PingwinStockWrite::find($this->writeId);
        if ($w && in_array($w->status, ['queued', 'running'], true)) {
            $w->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300), 'finished_at' => now()]);
        }
    }
}
