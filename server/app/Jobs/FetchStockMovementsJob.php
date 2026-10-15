<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Restaurant\StockMovementsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * XPLENDOR — Fatia 7b (SÓ LEITURA): lê no worker o relatório Movimentos de um intervalo de códigos e
 * deixa-o em cache (10 min) para o ecrã. Os pedidos HTTP não falam com o PingWin (só o worker chega ao
 * container do scraper).
 */
class FetchStockMovementsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 360;

    public function __construct(public int $companyId, public string $from, public string $to, public string $codeFrom, public string $codeTo) {}

    public function handle(StockMovementsService $movements): void
    {
        $key = StockMovementsService::key($this->companyId, $this->from, $this->to, $this->codeFrom, $this->codeTo);
        try {
            $movements->fetch($this->companyId, $this->from, $this->to, $this->codeFrom, $this->codeTo);
        } catch (\Throwable $e) {
            Cache::put($key . ':error', mb_substr($e->getMessage(), 0, 300), now()->addMinutes(2));
        } finally {
            Cache::forget($key . ':pending');
        }
    }
}
