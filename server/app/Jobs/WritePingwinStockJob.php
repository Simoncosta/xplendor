<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PingwinStockWrite;
use App\Models\PingwinWarehouse;
use App\Models\RestaurantStoreWarehouse;
use App\Services\PingwinService;
use App\Services\Restaurant\StockSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * XPLENDOR — Fatia 7a (⚠️ ESCREVE NO PINGWIN): UMA escrita do stock ou dos armazéns, iniciada por uma
 * pessoa. Uma tentativa só: nunca se repete sozinha. No fim relê, atualiza o espelho com a releitura e
 * compara-a com o pedido (ok / mismatch). Se o PingWin mudou desde a leitura, pára sem gravar (conflict).
 */
class WritePingwinStockJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 360;

    public function __construct(public int $writeId) {}

    public function middleware(): array
    {
        $w = PingwinStockWrite::find($this->writeId);

        return $w ? [(new WithoutOverlapping("pingwin-stock-write:{$w->company_id}"))->dontRelease()->expireAfter(600)] : [];
    }

    public function handle(PingwinService $pingwin, StockSyncService $sync): void
    {
        $write = PingwinStockWrite::find($this->writeId);
        if (! $write || $write->status !== 'queued') {
            return;
        }
        $write->update(['status' => 'running', 'started_at' => now()]);

        try {
            $res = $write->kind === 'limites'
                ? $pingwin->writeStockLimits($write->company_id, (string) $write->product_pingwin_id, (string) $write->warehouse_pingwin_id, (array) $write->payload, (array) $write->expected)
                : $pingwin->writeWarehouse($write->company_id, ['armazem_criar' => 'create', 'armazem_editar' => 'update', 'armazem_apagar' => 'delete'][$write->kind],
                    $write->warehouse_pingwin_id, $write->kind === 'armazem_apagar' ? [] : (array) $write->payload, $write->kind === 'armazem_editar' ? (array) $write->expected : null);
        } catch (\Throwable $e) {
            $write->update(['status' => 'failed', 'error' => self::human($e->getMessage()), 'finished_at' => now()]);

            return;
        }

        $result = array_intersect_key($res, array_flip(['saved', 'deleted', 'checks', 'before', 'after', 'diff', 'pingwin_id', 'code', 'in_state0_ativos', 'in_state1_anulados', 'no_stock_rows']));
        if ($res['conflict'] ?? false) {
            $write->update(['status' => 'conflict', 'result' => $result, 'finished_at' => now(),
                'error' => 'Os valores mudaram no PingWin desde a última leitura: nada foi gravado. Recarregue e volte a tentar.']);

            return;
        }
        if (! ($res['saved'] ?? false) && ! ($res['deleted'] ?? false)) {
            $write->update(['status' => 'failed', 'result' => $result, 'finished_at' => now(), 'error' => self::human((string) ($res['error'] ?? 'O PingWin não gravou.'))]);

            return;
        }

        // O espelho passa a ser o que o PingWin tem agora (mesmo que não bata com o pedido).
        try {
            $this->mirror($write, $res, $sync);
        } catch (\Throwable $e) {
            $result['mirror_error'] = mb_substr($e->getMessage(), 0, 300);
        }
        $ok = (bool) ($res['ok'] ?? false);
        $write->update([
            'status' => $ok ? 'ok' : 'mismatch', 'result' => $result, 'finished_at' => now(),
            'warehouse_pingwin_id' => $write->warehouse_pingwin_id ?? ($res['pingwin_id'] ?? null),
            'error' => $ok ? null : 'Gravado, mas a releitura não bate com o pedido: veja no PingWin.',
        ]);
    }

    private function mirror(PingwinStockWrite $write, array $res, StockSyncService $sync): void
    {
        if ($write->kind === 'limites') {
            if (is_array($res['reread'] ?? null)) {
                $sync->persist($write->company_id, (string) $write->product_pingwin_id, $res['reread']);
            }

            return;
        }
        if ($write->kind === 'armazem_apagar') {
            if ($res['deleted'] ?? false) {
                PingwinWarehouse::where('company_id', $write->company_id)->where('pingwin_id', $write->warehouse_pingwin_id)
                    ->update(['deleted' => true, 'synced_at' => now()]);
                RestaurantStoreWarehouse::where('company_id', $write->company_id)->where('warehouse_pingwin_id', $write->warehouse_pingwin_id)->delete();
            }

            return;
        }
        $r = $res['reread'] ?? null;
        if (is_array($r) && ($r['id'] ?? '') !== '') {
            PingwinWarehouse::updateOrCreate(['company_id' => $write->company_id, 'pingwin_id' => (string) $r['id']], [
                'code' => $r['code'] ?? null, 'description' => $r['description'] ?? null, 'deleted' => (bool) ($r['deleted'] ?? false),
                'inv_costmethod' => $r['inv_costmethod'] ?? null, 'exit_costmethod' => $r['exit_costmethod'] ?? null,
                'stores' => array_values((array) ($r['stores'] ?? [])), 'main_store_pingwin_id' => $r['main_store_id'] ?? null, 'synced_at' => now(),
            ]);
        }
    }

    /** A mensagem para o ecrã: a recusa da guarda e os erros do PingWin, sem pormenores técnicos. */
    private static function human(string $raw): string
    {
        if (str_contains($raw, 'escrita recusada')) {
            return 'Neste ambiente esta gravação não é permitida (guarda do dev).';
        }

        return mb_substr(PingwinService::humanError($raw), 0, 300);
    }

    public function failed(\Throwable $e): void
    {
        PingwinStockWrite::where('id', $this->writeId)->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300), 'finished_at' => now()]);
    }
}
