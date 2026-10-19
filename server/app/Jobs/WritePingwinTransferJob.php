<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PingwinStockWrite;
use App\Models\StockTransfer;
use App\Models\StockTransferRoute;
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
 * XPLENDOR — Fatia 7c (⚠️ ESCREVE NO PINGWIN): UMA transferência, por ação de uma pessoa. Uma tentativa só;
 * nunca reenvia sozinho.
 *  · guia_saida: a Guia 1102 (pela conversão da encomenda, P2, ou nova, P3) no armazém de origem, linhas na
 *    unidade de stock e ao PCM da origem, moradas de carga e descarga do circuito. Se o circuito recebe
 *    sozinho, a Entrada 1104 segue logo (P4); se a Entrada falhar, fica "falhou_rececao" (só se repete a
 *    entrada, por uma pessoa).
 *  · entrada_armazem: a Entrada pela conversão da Guia, com as quantidades recebidas.
 *  · anular_documento: anula a Entrada e depois a Guia (/service/document/<id>/header, estado 8003).
 * Depois de cada escrita relê o stock dos artigos (o tbstock reflete Guias e Entradas logo, provado no passo 0).
 */
class WritePingwinTransferJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 1200;
    /** Um timeout falha o job logo (nunca fica reservado para voltar a correr depois do retry_after). */
    public bool $failOnTimeout = true;

    public function __construct(public int $writeId) {}

    public function middleware(): array
    {
        $w = PingwinStockWrite::find($this->writeId);

        return $w ? [(new WithoutOverlapping("pingwin-stockdoc:{$w->company_id}"))->dontRelease()->expireAfter(1500)] : [];
    }

    public function handle(PingwinService $pingwin, StockSyncService $sync): void
    {
        $write = PingwinStockWrite::find($this->writeId);
        if (! $write || $write->status !== 'queued') {
            return;
        }
        $t = StockTransfer::with(['lines', 'route', 'request'])->where('company_id', $write->company_id)->find($write->payload['transfer_id'] ?? 0);
        if (! $t) {
            $write->update(['status' => 'failed', 'error' => 'O envio já não existe.', 'finished_at' => now()]);

            return;
        }
        $write->update(['status' => 'running', 'started_at' => now()]);
        match ($write->kind) {
            'guia_saida' => $this->exit($t, $write, $pingwin, $sync),
            'entrada_armazem' => $this->entry($t, $write, $pingwin, $sync),
            'anular_documento' => $this->void($t, $write, $pingwin, $sync),
            default => $write->update(['status' => 'failed', 'error' => 'Tipo de escrita desconhecido.', 'finished_at' => now()]),
        };
    }

    private function exit(StockTransfer $t, PingwinStockWrite $write, PingwinService $pingwin, StockSyncService $sync): void
    {
        if ($t->status !== 'a_enviar') {
            $write->update(['status' => 'failed', 'error' => 'O envio já não está para enviar.', 'finished_at' => now()]);

            return;
        }
        $route = $t->route;
        $fields = [
            'docconfig_id' => $t->exit_docconfig_id, 'entity_id' => $t->origin_warehouse_id, 'store_id' => $t->origin_store_id,
            'expected_warehouse' => $t->origin_warehouse_id, 'docstatus' => '8002', 'header_obs' => self::obs($t, 'Envio'),
            'addresses' => ['expedition' => $route?->load_address, 'delivery' => $route?->unload_address],
            'lines' => $t->lines->map(fn ($l) => ['product_id' => $l->product_id, 'code' => $l->code, 'unit_id' => $l->unit_id,
                'qnt' => (float) $l->qnt_base, 'price' => round((float) $l->origin_pcm, 6)])->values()->all(),
        ];
        if ($t->method === 'conversao' && $t->request) {
            $fields['source_id'] = $t->request->pingwin_doc_id;
        }
        $res = $this->call($pingwin, $t->company_id, $fields);
        if (! ($res['saved'] ?? false)) {
            $msg = self::failMessage($res);
            $t->update(['status' => 'falhou_envio', 'error_message' => $msg]);
            $write->update(['status' => 'failed', 'error' => $msg, 'result' => self::slim($res), 'finished_at' => now()]);

            return;
        }
        $this->storeLines($t, (array) ($res['lines'] ?? []), 'sent_price');
        $t->update(['status' => 'enviado', 'exit_doc_id' => $res['doc_id'] ?? null, 'exit_doc_number' => $res['doc_number'] ?? null, 'sent_at' => now(), 'error_message' => null]);
        $write->update(['status' => $this->linesMatch($t, (array) ($res['lines'] ?? []), $t->origin_warehouse_id) ? 'ok' : 'mismatch', 'result' => self::slim($res), 'finished_at' => now()]);
        if ($t->request) {
            $t->request->update(['converted' => $t->method === 'conversao']);
        }

        if ($t->auto_receive) {
            foreach ($t->lines as $l) {
                $l->update(['received_qnt_base' => $l->qnt_base]);
            }
            $entry = PingwinStockWrite::create(['company_id' => $t->company_id, 'user_id' => $write->user_id, 'kind' => 'entrada_armazem',
                'warehouse_pingwin_id' => $t->dest_warehouse_id, 'payload' => ['transfer_id' => $t->id, 'auto' => true], 'status' => 'running', 'started_at' => now()]);
            $this->entry($t->fresh(['lines', 'route', 'request']), $entry, $pingwin, $sync);
        } else {
            $this->reread($t, $pingwin, $sync);
        }
    }

    private function entry(StockTransfer $t, PingwinStockWrite $write, PingwinService $pingwin, StockSyncService $sync): void
    {
        if (! in_array($t->status, ['enviado', 'falhou_rececao'], true) || ! $t->exit_doc_id) {
            $write->update(['status' => 'failed', 'error' => 'O envio não está à espera de receção.', 'finished_at' => now()]);

            return;
        }
        $res = $this->call($pingwin, $t->company_id, [
            'docconfig_id' => (string) config('stock_transfers.entry_docconfig', '1104'), 'source_id' => $t->exit_doc_id, 'entity_id' => $t->dest_warehouse_id,
            'expected_warehouse' => $t->dest_warehouse_id, 'docstatus' => '8002', 'header_obs' => self::obs($t, 'Receção'),
            'lines' => $t->lines->map(fn ($l) => ['product_id' => $l->product_id, 'code' => $l->code, 'unit_id' => $l->unit_id,
                'qnt' => (float) ($l->received_qnt_base ?? $l->qnt_base), 'price' => round((float) $l->origin_pcm, 6)])->values()->all(),
        ]);
        if (! ($res['saved'] ?? false)) {
            $msg = self::failMessage($res);
            $t->update(['status' => 'falhou_rececao', 'error_message' => 'A Guia ' . $t->exit_doc_number . ' foi gravada, mas a Entrada falhou: ' . $msg]);
            $write->update(['status' => 'failed', 'error' => $msg, 'result' => self::slim($res), 'finished_at' => now()]);
            $this->reread($t, $pingwin, $sync);

            return;
        }
        $this->storeLines($t, (array) ($res['lines'] ?? []), 'received_price');
        $diff = $t->lines->contains(fn ($l) => abs((float) ($l->received_qnt_base ?? $l->qnt_base) - (float) $l->qnt_base) > 1e-6);
        $t->update(['status' => $diff ? 'recebido_com_diferenca' : 'recebido', 'entry_doc_id' => $res['doc_id'] ?? null, 'entry_doc_number' => $res['doc_number'] ?? null,
            'received_by' => $write->user_id, 'received_at' => now(), 'error_message' => null]);
        $write->update(['status' => $this->linesMatch($t, (array) ($res['lines'] ?? []), $t->dest_warehouse_id) ? 'ok' : 'mismatch', 'result' => self::slim($res), 'finished_at' => now()]);
        $this->reread($t, $pingwin, $sync);
    }

    private function void(StockTransfer $t, PingwinStockWrite $write, PingwinService $pingwin, StockSyncService $sync): void
    {
        $done = [];
        foreach (array_filter([$t->entry_doc_id => $t->entry_doc_number, $t->exit_doc_id => $t->exit_doc_number], fn ($n, $id) => $id !== '', ARRAY_FILTER_USE_BOTH) as $id => $number) {
            try {
                $res = $pingwin->stockDocument($t->company_id, 'stockdoc_status', ['doc_id' => (string) $id, 'status' => '8003']);
            } catch (\Throwable $e) {
                $res = ['ok' => false, 'error' => $e->getMessage()];
            }
            if (! ($res['ok'] ?? false)) {
                $msg = sprintf('Não foi possível anular %s: %s', $number, WritePingwinCountJob::human((string) ($res['error'] ?? 'erro desconhecido')));
                $t->update(['error_message' => $msg . ($done ? ' (já anulado: ' . implode(', ', $done) . ')' : '')]);
                $write->update(['status' => 'failed', 'error' => $msg, 'result' => ['voided' => $done], 'finished_at' => now()]);

                return;
            }
            $done[] = $number;
        }
        $t->update(['status' => 'anulado', 'voided_by' => $write->user_id, 'voided_at' => now(), 'error_message' => null]);
        if ($t->request && $t->method === 'conversao') {
            $t->request->update(['converted' => false]);
        }
        $write->update(['status' => 'ok', 'result' => ['voided' => $done], 'finished_at' => now()]);
        $this->reread($t, $pingwin, $sync);
    }

    private function call(PingwinService $pingwin, int $companyId, array $fields): array
    {
        try {
            return $pingwin->stockDocument($companyId, 'doc_write', $fields, 900);
        } catch (\Throwable $e) {
            return ['ok' => false, 'saved' => false, 'error' => $e->getMessage()];
        }
    }

    /** O preço por linha como ficou no PingWin (releitura do documento). */
    private function storeLines(StockTransfer $t, array $lines, string $col): void
    {
        $by = collect($lines)->keyBy(fn ($l) => (string) ($l['product_id'] ?? ''));
        foreach ($t->lines as $l) {
            if ($p = $by[$l->product_id] ?? null) {
                $l->update([$col => $p['price'] ?? null]);
            }
        }
    }

    /** As linhas gravadas batem com o envio (armazém, quantidade em unidades de stock e preço). */
    private function linesMatch(StockTransfer $t, array $lines, string $warehouseId): bool
    {
        $by = collect($lines)->keyBy(fn ($l) => (string) ($l['product_id'] ?? ''));
        foreach ($t->lines as $l) {
            $p = $by[$l->product_id] ?? null;
            $want = $warehouseId === $t->dest_warehouse_id ? (float) ($l->received_qnt_base ?? $l->qnt_base) : (float) $l->qnt_base;
            if (! $p || (string) ($p['warehouse_id'] ?? '') !== $warehouseId || abs((float) ($p['qnt'] ?? 0) - $want) > 1e-4
                || abs((float) ($p['price'] ?? 0) - round((float) $l->origin_pcm, 6)) > 1e-4) {
                return false;
            }
        }

        return true;
    }

    private function reread(StockTransfer $t, PingwinService $pingwin, StockSyncService $sync): void
    {
        try {
            (new StockDocuments($t->company_id, $pingwin, $sync))->reread($t->lines->pluck('product_id')->all(), $t->origin_warehouse_id);
        } catch (\Throwable) {
            // o espelho do stock atualiza-se no sync seguinte
        }
    }

    private static function obs(StockTransfer $t, string $what): string
    {
        return sprintf('XPLENDOR · %s %d%s', $what, $t->id, $t->request?->document ? ' · ' . $t->request->document : '');
    }

    private static function failMessage(array $res): string
    {
        $msg = WritePingwinCountJob::human((string) ($res['error'] ?? 'O PingWin não gravou o documento.'));
        if (! empty($res['lines_wrong_warehouse'])) {
            return $msg;
        }

        return str_contains($msg, 'gravad') ? $msg : $msg . ' Nada foi gravado no PingWin.';
    }

    private static function slim(array $res): array
    {
        return array_intersect_key($res, array_flip(['doc_id', 'doc_number', 'total', 'lines', 'trace', 'pingwin_message', 'verify_code', 'unit_mismatch']));
    }

    public function failed(\Throwable $e): void
    {
        $w = PingwinStockWrite::find($this->writeId);
        if ($w && in_array($w->status, ['queued', 'running'], true)) {
            $w->update(['status' => 'failed', 'error' => 'O trabalho parou: ' . mb_substr($e->getMessage(), 0, 200), 'finished_at' => now()]);
        }
    }
}
