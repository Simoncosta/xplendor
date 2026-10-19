<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PingwinStockWrite;
use App\Models\StockCount;
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
 * XPLENDOR — Fatia 7b (⚠️ ESCREVE NO PINGWIN): envia UMA contagem aprovada por um gestor (Contagem 1106,
 * loja dos documentos de stock, Fechado). Uma tentativa só: nunca se repete sozinha. A quantidade vai na
 * unidade de stock; se a linha do PingWin vier noutra unidade, pára antes de gravar e diz qual artigo.
 * Depois confirma: o documento na lista (Fechado, no armazém certo) e a releitura do stock de cada artigo.
 */
class WritePingwinCountJob implements ShouldQueue
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
        $count = StockCount::where('company_id', $write->company_id)->find($write->payload['count_id'] ?? 0);
        if (! $count || $count->status !== 'a_enviar') {
            $write->update(['status' => 'failed', 'error' => 'A contagem já não está para enviar.', 'finished_at' => now()]);

            return;
        }
        $write->update(['status' => 'running', 'started_at' => now()]);
        $lines = $count->lines()->whereNotNull('qty_base')->get();
        $fail = function (string $msg, array $result = []) use ($write, $count) {
            $count->update(['status' => 'falhou', 'error_message' => $msg]);
            $write->update(['status' => 'failed', 'error' => $msg, 'result' => $result ?: null, 'finished_at' => now()]);
        };

        $noUnit = $lines->filter(fn ($l) => ! $l->stock_unit_id)->pluck('code')->all();
        if ($noUnit) {
            $fail('Não se sabe a unidade de stock de ' . implode(', ', array_slice($noUnit, 0, 5)) . ' (o stock ainda não foi lido). O documento não foi gravado no PingWin.');

            return;
        }
        $store = StockDocuments::storeId($count->company_id);
        if (! $store) {
            $fail('Não se sabe em que loja fazer o documento (defina STOCK_DOC_STORE_ID). O documento não foi gravado no PingWin.');

            return;
        }

        try {
            $res = $pingwin->stockDocument($count->company_id, 'count_write', [
                'store_id' => $store, 'warehouse_id' => $count->warehouse_id, 'inventory_date' => $count->dateString(), 'docstatus' => '8002',
                'lines' => $lines->map(fn ($l) => ['product_id' => $l->product_id, 'code' => $l->code, 'description' => $l->description,
                    'physical_qnt' => round((float) $l->qty_base, 5), 'unit_id' => $l->stock_unit_id])->values()->all(),
            ], 900);
        } catch (\Throwable $e) {
            $fail(self::human($e->getMessage()) . ' O documento não foi gravado no PingWin.');

            return;
        }
        if (! ($res['saved'] ?? false)) {
            $msg = (string) ($res['error'] ?? 'O PingWin não gravou o documento.');
            $fail(str_contains($msg, 'não foi gravado') || str_contains($msg, 'Nada foi gravado') ? self::human($msg) : self::human($msg) . ' O documento não foi gravado no PingWin.',
                array_intersect_key($res, array_flip(['pingwin_message', 'steps', 'unit_mismatch', 'code'])));

            return;
        }

        $byPid = collect($res['lines'] ?? [])->keyBy('product_id');
        foreach ($lines as $l) {
            $p = $byPid[$l->product_id] ?? null;
            $l->forceFill(['pingwin_unit_id' => $p['unit_id'] ?? null, 'pingwin_theoretical_qnt' => $p['theoretical_qnt'] ?? null, 'pingwin_unit_cost' => $p['unit_cost'] ?? null,
                'pingwin_qnt_difference' => $p['qnt_difference'] ?? null, 'pingwin_price_difference' => $p['price_difference'] ?? null])->save();
        }
        $count->update(['status' => 'enviada', 'pingwin_doc_id' => $res['doc_id'] ?? null, 'pingwin_doc_number' => $res['doc_number'] ?? null, 'sent_at' => now(), 'error_message' => null]);

        // Confirmação: o documento na lista (Fechado, no armazém certo) e a quantidade de cada linha no
        // relatório Movimentos. O stock do artigo (tbstock) não reflete a Contagem logo a seguir (Fatia 7c,
        // passo 0-A); o espelho do stock relê-se na mesma, para o resto do ecrã.
        $result = ['doc_id' => $res['doc_id'] ?? null, 'doc_number' => $res['doc_number'] ?? null, 'steps' => $res['steps'] ?? []];
        $docs = new StockDocuments($count->company_id, $pingwin, $sync);
        try {
            $doc = $docs->find((string) $res['doc_id'], $count->dateString(), 'count');
            $result['listed'] = $doc ? ['status' => $doc['docstatus_id'] ?? null, 'warehouse_id' => $doc['entity_id'] ?? null, 'document' => $doc['document'] ?? null] : null;
            $moved = $docs->documentMovements((string) $res['doc_id'], $count->dateString(), $lines->pluck('code')->filter()->all(), (string) $count->warehouse_id);
            $result['movements'] = $moved;
            try {
                $docs->reread($lines->pluck('product_id')->all(), $count->warehouse_id);
            } catch (\Throwable) {
                // o espelho atualiza-se no sync seguinte
            }
            foreach ($lines as $l) {
                $now = $moved[(string) $l->code] ?? null;
                $l->forceFill(['confirmed_qty' => $now, 'confirm_status' => $now !== null && abs($now - (float) $l->qty_base) < 1e-4 ? 'confirmado' : 'diferente'])->save();
            }
            $ok = $doc && (string) ($doc['docstatus_id'] ?? '') === '8002' && (string) ($doc['entity_id'] ?? '') === (string) $count->warehouse_id
                && $lines->every(fn ($l) => $l->fresh()->confirm_status === 'confirmado');
            $count->update(['confirmed_at' => $ok ? now() : null]);
            $write->update(['status' => $ok ? 'ok' : 'mismatch', 'result' => $result, 'finished_at' => now(),
                'error' => $ok ? null : 'Gravado no PingWin, mas a confirmação não bate: veja as linhas marcadas como diferentes.']);
        } catch (\Throwable $e) {
            $write->update(['status' => 'mismatch', 'result' => $result, 'finished_at' => now(), 'error' => 'Gravado no PingWin, mas não foi possível confirmar: ' . mb_substr($e->getMessage(), 0, 200)]);
        }
    }

    public static function human(string $raw): string
    {
        if (str_contains($raw, 'escrita recusada')) {
            return 'Neste ambiente esta gravação não é permitida (guarda do dev).';
        }
        $t = str_replace(['á', 'á', '\r\n', "\r\n", "\r", "\n"], ['á', 'á', ' ', ' ', ' ', ' '], $raw);
        if (str_contains($t, 'existe outro invent')) {
            // O PingWin aceita uma só contagem de cada artigo por dia e por armazém (Fatia 7c, passo 0-A).
            $art = preg_match('/com o artigo\s*:\s*([^"}]+)/u', $t, $m) ? trim($m[1]) : '';

            return 'O PingWin já tem outra contagem' . ($art !== '' ? " de {$art}" : '') . ' nesta data. Anule a anterior ou escolha o outro momento (antes da abertura ou depois do fecho).';
        }

        return mb_substr(PingwinService::humanError(preg_replace('/^RuntimeError: /', '', $raw) ?? $raw), 0, 500);
    }

    public function failed(\Throwable $e): void
    {
        $w = PingwinStockWrite::find($this->writeId);
        if ($w && in_array($w->status, ['queued', 'running'], true)) {
            $w->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300), 'finished_at' => now()]);
            StockCount::where('id', $w->payload['count_id'] ?? 0)->where('status', 'a_enviar')->update(['status' => 'falhou', 'error_message' => 'O envio falhou. O documento não foi gravado no PingWin.']);
        }
    }
}
