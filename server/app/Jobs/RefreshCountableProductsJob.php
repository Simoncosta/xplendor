<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Restaurant\CountableProductsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/** XPLENDOR — Fatia 7b (SÓ LEITURA): relê no worker a lista dos artigos contáveis, a pedido de um gestor. */
class RefreshCountableProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 360;
    /** Um timeout falha o job logo (nunca fica reservado para voltar a correr depois do retry_after). */
    public bool $failOnTimeout = true;

    public function __construct(public int $companyId) {}

    public function handle(CountableProductsService $service): void
    {
        try {
            $service->refresh($this->companyId);
        } catch (\Throwable $e) {
            Log::warning('[PingWin Stock] contáveis falharam', ['company_id' => $this->companyId, 'error' => $e->getMessage()]);
        }
    }
}
