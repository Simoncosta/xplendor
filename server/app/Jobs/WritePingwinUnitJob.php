<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PingwinCatalogItem;
use App\Models\PingwinUnitWrite;
use App\Services\PingwinService;
use App\Services\Restaurant\Units\UnitProfileService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — Auditoria v2 (⚠️ ESCREVE NO PINGWIN): UMA alteração às unidades de um artigo (um
 * PingwinUnitWrite), iniciada por uma pessoa. Uma tentativa só, uma de cada vez por artigo. O
 * Python lê o artigo antes, grava e relê; aqui guarda-se o antes e o depois, atualiza-se o espelho
 * das unidades (e a unidade de compra do artigo) e diz-se ok, mismatch, refused ou failed.
 */
class WritePingwinUnitJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 660;

    public function __construct(public int $writeId) {}

    public function middleware(): array
    {
        $w = PingwinUnitWrite::find($this->writeId);

        return $w ? [(new WithoutOverlapping("pingwin-unit-write:{$w->company_id}:{$w->product_pingwin_id}"))->dontRelease()->expireAfter(900)] : [];
    }

    public function handle(PingwinService $pingwin): void
    {
        $write = PingwinUnitWrite::find($this->writeId);
        if (! $write || $write->status !== 'queued') {
            return;
        }
        $write->update(['status' => 'running', 'started_at' => now(), 'progress' => ['steps' => [], 'current' => 'ler']]);
        $steps = [];
        // As unidades que a XPLENDOR já criou para este artigo (para as poder anular ou corrigir).
        $owned = PingwinUnitWrite::where('company_id', $write->company_id)->where('product_pingwin_id', $write->product_pingwin_id)
            ->where('op', 'create')->whereIn('status', ['ok', 'mismatch'])->whereNotNull('unit_pingwin_id')->pluck('unit_pingwin_id')->all();

        try {
            $res = $pingwin->writeArticleUnit($write->company_id, $write->product_pingwin_id, $write->op, (array) $write->data, $owned,
                function (array $step) use ($write, &$steps) {
                    $steps[] = $step + ['at' => now()->toIso8601String()];
                    $write->forceFill(['progress' => ['steps' => $steps, 'current' => $step['step'] ?? null]])->save();
                });
        } catch (\Throwable $e) {
            Log::warning('[PingWin] escrita de unidade falhou', ['write' => $write->id, 'error' => mb_substr($e->getMessage(), 0, 500)]);
            $write->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000), 'finished_at' => now()]);

            return;
        }

        if ($res['refused'] ?? false) {
            $write->update(['status' => 'refused', 'error' => (string) ($res['error'] ?? 'Recusado.'), 'finished_at' => now()]);

            return;
        }
        if (! isset($res['after'])) {
            $write->update(['status' => 'failed', 'error' => (string) ($res['error'] ?? 'Sem resposta do PingWin.'), 'finished_at' => now()]);

            return;
        }
        $unitId = (string) ($res['unit']['id'] ?? ($write->data['unit_id'] ?? ''));
        $write->update([
            'status' => ($res['ok'] ?? false) ? 'ok' : 'mismatch',
            'product_code' => $res['before']['code'] ?? $write->product_code,
            'unit_pingwin_id' => $unitId !== '' ? $unitId : null,
            'before' => $res['before'] ?? null,
            'after' => $res['after'] ?? null,
            'note' => mb_substr((string) ($res['note'] ?? ''), 0, 255),
            'finished_at' => now(),
        ]);

        // O espelho passa a ser o que o PingWin tem agora (mesmo que não bata).
        try {
            $pingwin->syncUnits($write->company_id);
        } catch (\Throwable $e) {
            Log::info('[PingWin] reler unidades depois da escrita falhou', ['error' => mb_substr($e->getMessage(), 0, 300)]);
        }
        $after = (array) ($res['after'] ?? []);
        PingwinCatalogItem::where('company_id', $write->company_id)->where('pingwin_id', $write->product_pingwin_id)
            ->update(array_filter([
                'default_purchase_unit_id' => $after['default_purchase_unit_id'] ?? null,
                'base_unit_id' => $after['base_unit_id'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''));

        if ($write->profile_id && $write->status === 'ok') {
            app(UnitProfileService::class, ['companyId' => $write->company_id])->afterPingwinWrite($write);
        }
    }
}
