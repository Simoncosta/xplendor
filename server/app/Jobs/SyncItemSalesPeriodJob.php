<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\AlertService;
use App\Services\PingwinItemSalesService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — F1-2: o "Sincronizar período" manual relê também as vendas por artigo do
 * período (blocos de 7 dias, 20 s entre pedidos). Despachado no fim do batch dos dias,
 * para a conferência já ter os líquidos diários, e só com o interruptor ligado. Corre no
 * worker (docker socket), serializado com as outras sincronizações PingWin da empresa.
 */
class SyncItemSalesPeriodJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Esperar pela vez (WithoutOverlapping) não gasta a tentativa; uma exceção sim. */
    public int $tries = 100;
    public int $maxExceptions = 1;
    public int $timeout = 1500; // até 14 pedidos de 7 dias (92 dias), com espaçamento

    public function __construct(public int $companyId, public string $from, public string $to) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("pingwin-sync:{$this->companyId}"))->releaseAfter(30)->expireAfter(1800)];
    }

    public function handle(PingwinItemSalesService $service, AlertService $alerts): void
    {
        if (! PingwinItemSalesService::isEnabled($this->companyId)) {
            return; // o interruptor foi desligado entretanto
        }
        // Só dias fechados: o período manual pode incluir hoje.
        $to = min($this->to, CarbonImmutable::yesterday()->toDateString());
        if ($this->from > $to) {
            return;
        }

        try {
            $result = $service->sync($this->companyId, $this->from, $to, false, 92);
        } catch (\Throwable $e) {
            Log::warning('[PingWin Vendas por artigo] período falhou', ['company_id' => $this->companyId, 'error' => $e->getMessage()]);
            $alerts->createSystemAlert(
                companyId: $this->companyId,
                type: 'warning',
                title: 'Vendas por artigo: período com erro',
                message: "Não foi possível reler as vendas por artigo de {$this->from} a {$to}.",
                severity: 'medium',
                detailPath: '/restauracao',
            );

            return;
        }

        $marked = count(array_filter($result['days'], fn ($d) => in_array($d['status'], ['mismatch', 'empty_protected'], true)));
        Log::info('[PingWin Vendas por artigo] período relido', [
            'company_id' => $this->companyId, 'from' => $this->from, 'to' => $to, 'pedidos' => $result['calls'], 'dias_marcados' => $marked,
        ]);
    }
}
