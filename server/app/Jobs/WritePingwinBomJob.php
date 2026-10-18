<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\RecipeDraft;
use App\Models\RecipeWrite;
use App\Services\PingwinBomSyncService;
use App\Services\PingwinService;
use App\Services\Restaurant\RecipeWriteService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — Fatia 4 (⚠️ ESCREVE NA PINGWIN): grava UMA ficha técnica (um RecipeWrite), iniciada
 * por uma pessoa. Uma tentativa só: nunca se repete sozinha. No fim relê a ficha, atualiza o
 * espelho desse artigo e compara a releitura com o pedido (ok / mismatch). Se a ficha mudou na
 * PingWin desde a leitura, pára sem gravar (conflict).
 */
class WritePingwinBomJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 660;

    public function __construct(public int $writeId) {}

    public function middleware(): array
    {
        $w = RecipeWrite::find($this->writeId);

        return $w ? [(new WithoutOverlapping("pingwin-bom-write:{$w->company_id}:{$w->product_pingwin_id}"))->dontRelease()->expireAfter(900)] : [];
    }

    public function handle(PingwinService $pingwin, PingwinBomSyncService $sync): void
    {
        $write = RecipeWrite::find($this->writeId);
        if (! $write || $write->status !== 'queued') {
            return;
        }
        $write->update(['status' => 'running', 'started_at' => now(), 'progress' => ['steps' => [], 'current' => 'abrir']]);
        $steps = [];

        try {
            $res = $pingwin->writeBom($write->company_id, $write->product_pingwin_id, $write->plan, function (array $step) use ($write, &$steps) {
                $steps[] = $step + ['at' => now()->toIso8601String()];
                $write->forceFill(['progress' => ['steps' => $steps, 'current' => $step['step'] ?? null] + array_intersect_key($step, array_flip(['n', 'm']))])->save();
            });

            if ($res['conflict'] ?? false) {
                $write->update(['status' => 'conflict', 'result' => ['diff' => $res['diff'] ?? []], 'finished_at' => now(),
                    'error' => 'A ficha mudou no PingWin desde a última leitura: recarregue e volte a editar.']);

                return;
            }

            $refs = $res['refs'] ?? [];
            $reread = $res['reread'] ?? null;
            if (! is_array($reread)) {
                // Gravado (SAVE), mas a releitura falhou: fica como "não bate", sem repetir.
                $write->update(['status' => 'mismatch', 'bom_pingwin_id' => $refs['new']['id'] ?? $write->bom_pingwin_id,
                    'result' => ['saved' => (bool) ($res['saved'] ?? false), 'refs' => $refs, 'diff' => ['Não foi possível reler a ficha no PingWin: ' . ($res['reread_error'] ?? 'erro desconhecido') . '.']],
                    'error' => 'Gravado, mas sem confirmação por releitura: veja a ficha no PingWin.', 'finished_at' => now()]);

                return;
            }
            // O espelho deste artigo passa a ser o que o PingWin tem agora (mesmo que não bata).
            if (($reread['id'] ?? '') !== '') {
                $sync->persist($write->company_id, $reread);
            }
            $diff = RecipeWriteService::compare($write, $reread, $refs);
            $write->update([
                'status' => $diff ? 'mismatch' : 'ok',
                'bom_pingwin_id' => $refs['new']['id'] ?? $write->bom_pingwin_id,
                'result' => ['saved' => (bool) ($res['saved'] ?? false), 'refs' => $refs, 'diff' => $diff, 'article' => $res['article'] ?? null],
                'error' => $diff ? 'A releitura no PingWin não bate com o pedido: veja as diferenças.' : null,
                'finished_at' => now(),
            ]);
            if (! $diff) {
                RecipeDraft::where('company_id', $write->company_id)->where('product_pingwin_id', $write->product_pingwin_id)
                    ->where('user_id', $write->user_id)->delete();
            }
            // Fatia 3: a ficha mudou; a engenharia de menu recalcula-se.
            ComputeMenuEngineeringJob::forDefaults((int) $write->company_id);
        } catch (\Throwable $e) {
            Log::warning('[PingWin Fichas] gravação falhou', ['write_id' => $write->id, 'error' => $e->getMessage()]);
            $write->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000), 'finished_at' => now()]);
        }
    }

    public function failed(\Throwable $e): void
    {
        RecipeWrite::where('id', $this->writeId)->whereIn('status', RecipeWrite::ACTIVE)
            ->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000), 'finished_at' => now()]);
    }
}
