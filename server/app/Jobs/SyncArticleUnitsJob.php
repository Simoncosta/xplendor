<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CompanyIntegration;
use App\Services\Restaurant\ArticleUnitsSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Catálogo da PingWin (SÓ LEITURA): os ids das unidades em todos os artigos ativos. Sem empresa, despacha
 * um ciclo por empresa com PingWin ligado; com empresa, lê UM lote de até 50 artigos (um docker exec) e
 * despacha o seguinte. Os lotes correm em sequência, cada um dentro do timeout.
 */
class SyncArticleUnitsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 960;      // docker exec até 900 s + margem
    /** Um timeout falha o job logo (nunca fica reservado para voltar a correr depois do retry_after). */
    public bool $failOnTimeout = true;
    public int $maxExceptions = 1;

    /** @param list<string>|null $remaining */
    public function __construct(public ?int $companyId = null, public ?array $remaining = null) {}

    public function middleware(): array
    {
        return $this->companyId ? [(new WithoutOverlapping("pingwin-units:{$this->companyId}"))->releaseAfter(60)->expireAfter(1200)] : [];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(3);
    }

    public function handle(ArticleUnitsSyncService $sync): void
    {
        if ($this->companyId === null) {
            $companyIds = CompanyIntegration::where('platform', 'pingwin')->where('status', '!=', 'revoked')
                ->pluck('company_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
            foreach ($companyIds as $companyId) {
                self::dispatch($companyId, $sync->candidates($companyId));
            }

            return;
        }

        $remaining = $this->remaining ?? $sync->candidates($this->companyId);
        $batch = array_slice($remaining, 0, ArticleUnitsSyncService::BATCH_SIZE);
        $rest = array_values(array_slice($remaining, ArticleUnitsSyncService::BATCH_SIZE));
        if ($batch) {
            try {
                $stats = $sync->syncBatch($this->companyId, $batch);
                Log::info('[PingWin Unidades] lote concluído', ['company_id' => $this->companyId] + array_intersect_key($stats, array_flip(['articles', 'changed', 'failed'])));
            } catch (\Throwable $e) {
                // O lote falhou inteiro (ex.: login): regista e segue para o próximo.
                Log::warning('[PingWin Unidades] lote falhou', ['company_id' => $this->companyId, 'error' => $e->getMessage()]);
            }
        }
        if ($rest) {
            self::dispatch($this->companyId, $rest);
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('[PingWin Unidades] job falhou', ['company_id' => $this->companyId, 'error' => $e->getMessage()]);
    }
}
