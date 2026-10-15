<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PingwinStockRun;
use App\Services\Restaurant\StockSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — Stock do PingWin (Fatia 7a, SÓ LEITURA): UM lote de até 50 artigos (um docker exec). O
 * primeiro lote lê também os armazéns. Se ainda houver artigos, despacha o lote seguinte; os lotes
 * correm em sequência, cada um dentro do timeout. No último, fecha o registo da leitura.
 */
class SyncPingwinStockJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 960;      // docker exec até 900 s + margem
    public int $maxExceptions = 1;

    /** @param list<string> $remaining */
    public function __construct(public int $companyId, public int $runId, public array $remaining, public bool $withWarehouses = false) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("pingwin-stock:{$this->companyId}"))->releaseAfter(60)->expireAfter(1200)];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(3);
    }

    public function handle(StockSyncService $sync): void
    {
        $run = PingwinStockRun::find($this->runId);
        if ($this->withWarehouses) {
            try {
                $sync->syncWarehouses($this->companyId);
            } catch (\Throwable $e) {
                Log::warning('[PingWin Stock] armazéns falharam', ['company_id' => $this->companyId, 'error' => $e->getMessage()]);
            }
            // Fatia 7b: a lista dos artigos contáveis (só leitura), uma vez por noite.
            try {
                app(\App\Services\Restaurant\CountableProductsService::class)->refresh($this->companyId);
            } catch (\Throwable $e) {
                Log::warning('[PingWin Stock] contáveis falharam', ['company_id' => $this->companyId, 'error' => $e->getMessage()]);
            }
            // Fatia 7c: as encomendas dos restaurantes (só leitura), num trabalho à parte (pode demorar).
            SyncTransferOrdersJob::dispatch($this->companyId);
        }

        $batch = array_slice($this->remaining, 0, StockSyncService::BATCH_SIZE);
        $rest = array_values(array_slice($this->remaining, StockSyncService::BATCH_SIZE));
        if ($batch) {
            try {
                $stats = $sync->syncBatch($this->companyId, $batch);
                if ($run) {
                    $sync->addToRun($run, $stats);
                }
                Log::info('[PingWin Stock] lote concluído', ['company_id' => $this->companyId] + array_intersect_key($stats, array_flip(['articles', 'rows', 'failed'])));
            } catch (\Throwable $e) {
                // O lote falhou inteiro (ex.: login): regista e segue para o próximo.
                $run?->increment('products_failed', count($batch));
                Log::warning('[PingWin Stock] lote falhou', ['company_id' => $this->companyId, 'error' => $e->getMessage()]);
            }
        }

        if ($rest) {
            self::dispatch($this->companyId, $this->runId, $rest, false);
        } elseif ($run) {
            $sync->finishRun($run);
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('[PingWin Stock] job falhou', ['company_id' => $this->companyId, 'error' => $e->getMessage()]);
        if ($run = PingwinStockRun::find($this->runId)) {
            app(StockSyncService::class)->finishRun($run, mb_substr($e->getMessage(), 0, 500));
        }
    }
}
