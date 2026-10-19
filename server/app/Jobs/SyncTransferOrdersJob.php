<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Restaurant\Transfers\TransferOrdersService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/** XPLENDOR — Fatia 7c (SÓ LEITURA): relê no worker as encomendas dos restaurantes, a pedido. O ecrã acompanha pela cache. */
class SyncTransferOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 1200;
    /** Um timeout falha o job logo (nunca fica reservado para voltar a correr depois do retry_after). */
    public bool $failOnTimeout = true;

    public function __construct(public int $companyId) {}

    public static function cacheKey(int $companyId): string
    {
        return "stock-transfers:orders-sync:{$companyId}";
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping("stock-transfer-orders:{$this->companyId}"))->dontRelease()->expireAfter(1500)];
    }

    public function handle(TransferOrdersService $service): void
    {
        Cache::put(self::cacheKey($this->companyId), ['status' => 'running', 'started_at' => now()->toIso8601String()], now()->addHour());
        try {
            $r = $service->sync($this->companyId);
            Cache::put(self::cacheKey($this->companyId), ['status' => 'done', 'finished_at' => now()->toIso8601String()] + $r, now()->addHour());
        } catch (\Throwable $e) {
            Log::warning('[PingWin Stock] encomendas falharam', ['company_id' => $this->companyId, 'error' => $e->getMessage()]);
            Cache::put(self::cacheKey($this->companyId), ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300)], now()->addHour());
        }
    }
}
