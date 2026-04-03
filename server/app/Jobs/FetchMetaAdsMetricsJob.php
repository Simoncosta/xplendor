<?php

namespace App\Jobs;

use App\Models\CarAdCampaign;
use App\Models\CompanyIntegration;
use App\Services\MetaAdsCarSyncService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchMetaAdsMetricsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 300;

    public function __construct(
        private readonly Carbon $date = new Carbon('yesterday')
    ) {}

    public function handle(MetaAdsCarSyncService $metaAdsSyncService): void
    {
        // Busca todas as integrações Meta activas
        $integrations = CompanyIntegration::active()
            ->platform('meta')
            ->get();

        if ($integrations->isEmpty()) {
            Log::info('FetchMetaAdsMetricsJob: sem integrações Meta activas.');
            return;
        }

        $dateStr = $this->date->toDateString();

        foreach ($integrations as $integration) {

            // Verificar se o token está expirado
            if ($integration->isTokenExpired()) {
                $integration->update(['status' => 'expired']);
                Log::warning('FetchMetaAdsMetricsJob: token expirado', [
                    'company_id' => $integration->company_id,
                ]);
                continue;
            }

            // Buscar todos os mapeamentos activos desta empresa
            $mappings = CarAdCampaign::active()
                ->platform('meta')
                ->where('company_id', $integration->company_id)
                ->with('car')
                ->get();

            if ($mappings->isEmpty()) {
                continue;
            }

            foreach ($mappings as $mapping) {
                $this->processMappingForDay($integration, $mapping, $metaAdsSyncService, $dateStr);
            }

            // Marcar como sincronizado
            $integration->update(['last_synced_at' => now(), 'error_message' => null]);
        }
    }

    private function processMappingForDay(
        CompanyIntegration $integration,
        CarAdCampaign $mapping,
        MetaAdsCarSyncService $metaAdsSyncService,
        string $dateStr
    ): void {
        try {
            $result = $metaAdsSyncService->refreshMappingForDay($integration, $mapping, $dateStr);

            Log::info('FetchMetaAdsMetricsJob: processado', [
                'car_id'      => $mapping->car_id,
                'company_id'  => $mapping->company_id,
                'mapping_id'  => $mapping->id,
                'metric_updated' => $result['metric_updated'] ?? false,
                'targeting_updated' => $result['targeting_updated'] ?? false,
                'date'        => $dateStr,
            ]);
        } catch (\Throwable $e) {
            Log::error('FetchMetaAdsMetricsJob: erro no mapeamento', [
                'mapping_id' => $mapping->id,
                'car_id'     => $mapping->car_id,
                'error'      => $e->getMessage(),
            ]);

            // Não falha o job inteiro — continua para os outros carros
        }
    }
}
