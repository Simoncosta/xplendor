<?php

namespace App\Jobs;

use App\Services\PingwinService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — LEITURA de um artigo pelo id (readProduct), no WORKER (root). Mesmo
 * princípio do ReadPingwinFormLookupsJob: docker exec só no worker; leitura idempotente
 * (tries=3); resultado em Redis (token+TTL) para a UI fazer polling e consumir.
 */
class ReadPingwinProductJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 3;
    public int $timeout = 120;

    public function __construct(public int $companyId, public string $productId, public string $token) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("pingwin-sync:{$this->companyId}"))->releaseAfter(10)->expireAfter(300)];
    }

    public function handle(PingwinService $pingwin): void
    {
        $article = $pingwin->readProduct($this->companyId, $this->productId); // pode lançar → retry
        Cache::put(
            PingwinService::readKey($this->companyId, $this->token),
            ['status' => 'ready', 'result' => ['article' => $article]],
            now()->addSeconds(120)
        );
    }

    public function failed(\Throwable $e): void
    {
        Cache::put(
            PingwinService::readKey($this->companyId, $this->token),
            ['status' => 'error', 'error_message' => PingwinService::humanError($e->getMessage())],
            now()->addSeconds(120)
        );
        Log::warning('[PingWin Ler Artigo] Falhou', ['company_id' => $this->companyId, 'product_id' => $this->productId, 'error' => $e->getMessage()]);
    }
}
