<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CompanyIntegration;
use App\Services\Restaurant\Forecast\FutureReservationsService;
use App\Services\Restaurant\Forecast\RestaurantForecastService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Fatia 6a: de manhã e ao fim da tarde, as reservas dos próximos 30 dias de cada empresa com o
 * CoverManager (SÓ LEITURA, uma fotografia por leitura) e, a seguir, a previsão e o erro medido de
 * todas as empresas com vendas do PingWin.
 */
class SyncFutureReservationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 600;
    /** Um timeout falha o job logo (nunca fica reservado para voltar a correr depois do retry_after). */
    public bool $failOnTimeout = true;

    public function __construct(public ?int $companyId = null) {}

    public function handle(FutureReservationsService $reservations): void
    {
        $companies = $this->companyId ? [$this->companyId]
            : CompanyIntegration::whereIn('platform', ['pingwin', 'covermanager'])->where('status', '!=', 'revoked')->pluck('company_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        foreach ($companies as $companyId) {
            try {
                $r = $reservations->sync($companyId);
                $f = (new RestaurantForecastService($companyId))->run();
                Log::info('[Previsão] reservas e previsão', ['company_id' => $companyId, 'reservas' => $r, 'lojas' => count($f)]);
            } catch (\Throwable $e) {
                Log::warning('[Previsão] falhou', ['company_id' => $companyId, 'error' => mb_substr($e->getMessage(), 0, 300)]);
            }
        }
    }
}
