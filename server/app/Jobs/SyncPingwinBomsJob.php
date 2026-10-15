<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\PingwinBomSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — Fichas técnicas (Fatia 1, SÓ LEITURA): UM lote de até 25 artigos (um docker exec).
 * Se ainda houver artigos, despacha o lote seguinte; os lotes correm em sequência, cada um dentro
 * do timeout. $done = os artigos já lidos neste ciclo (os semi-produtos não se leem duas vezes).
 */
class SyncPingwinBomsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 660;      // docker exec até 600 s + margem
    public int $maxExceptions = 1;

    /**
     * @param  list<string>  $remaining
     * @param  list<string>  $done
     */
    public function __construct(public int $companyId, public array $remaining, public array $done) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("pingwin-boms:{$this->companyId}"))->releaseAfter(60)->expireAfter(900)];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(2);
    }

    public function handle(PingwinBomSyncService $sync): void
    {
        $remaining = array_values(array_diff($this->remaining, $this->done));
        $batch = array_slice($remaining, 0, PingwinBomSyncService::BATCH_SIZE);
        if (! $batch) {
            return;
        }

        try {
            $stats = $sync->syncBatch($this->companyId, $batch, false, $this->done);
            $read = $stats['read'];
            unset($stats['read']);
            Log::info('[PingWin Fichas] lote concluído', ['company_id' => $this->companyId] + $stats);
        } catch (\Throwable $e) {
            // O lote falhou inteiro (ex.: login): regista e segue para o próximo.
            Log::warning('[PingWin Fichas] lote falhou', ['company_id' => $this->companyId, 'error' => $e->getMessage()]);
            $read = [];
        }

        $done = array_values(array_unique(array_merge($this->done, $batch, $read)));
        $rest = array_values(array_diff($remaining, $done));
        if ($rest) {
            self::dispatch($this->companyId, $rest, $done);
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('[PingWin Fichas] job falhou', ['company_id' => $this->companyId, 'error' => $e->getMessage()]);
    }
}
