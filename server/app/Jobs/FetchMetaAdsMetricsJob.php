<?php

namespace App\Jobs;

use App\Models\CarAdCampaign;
use App\Models\CarPerformanceMetric;
use App\Models\CompanyIntegration;
use App\Models\MetaAudienceInsight;
use App\Services\MetaAdsService;
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

    public function handle(MetaAdsService $metaAds): void
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
                $this->processMappingForDay($integration, $mapping, $metaAds, $dateStr);
            }

            // Marcar como sincronizado
            $integration->update(['last_synced_at' => now(), 'error_message' => null]);
        }
    }

    private function processMappingForDay(
        CompanyIntegration $integration,
        CarAdCampaign $mapping,
        MetaAdsService $metaAds,
        string $dateStr
    ): void {
        try {
            // 1. Buscar métricas agregadas do adset
            $insights = $metaAds->getAdsetInsights(
                $integration->access_token,
                $mapping->external_id,
                $dateStr,
                $dateStr
            );

            if (empty($insights)) {
                return; // Adset sem dados para este dia (normal)
            }

            // 2. Aplicar split se o adset está mapeado a vários carros
            $splitFactor = $mapping->spend_split_pct / 100;

            $spend       = round((float) ($insights['spend']       ?? 0) * $splitFactor, 2);
            $impressions = (int) round((float) ($insights['impressions'] ?? 0) * $splitFactor);
            $clicks      = (int) round((float) ($insights['clicks']      ?? 0) * $splitFactor);
            $reach       = (int) round((float) ($insights['reach']       ?? 0) * $splitFactor);

            // 3. Upsert no car_performance_metrics — NUNCA sobrescreve dados comportamentais
            // (sessions, leads, interactions vêm do AggregateCarPerformanceMetricsJob)
            $metric = CarPerformanceMetric::firstOrCreate(
                [
                    'car_id'       => $mapping->car_id,
                    'company_id'   => $mapping->company_id,
                    'channel'      => 'paid',
                    'period_start' => $dateStr,
                    'period_end'   => $dateStr,
                ],
                [
                    'sessions'           => 0,
                    'leads_count'        => 0,
                    'interactions_count' => 0,
                    'spend_amount'       => 0,
                    'impressions'        => 0,
                    'clicks'             => 0,
                    'data_source'        => 'meta_ads',
                ]
            );

            $metric->update([
                'spend_amount' => $spend,
                'impressions'  => $impressions,
                'clicks'       => $clicks,
                'data_source'  => 'meta_ads',
            ]);

            // Recalcular métricas derivadas (CTR, CPC, cost_per_lead)
            $metric->recalculate();

            // 4. Buscar e guardar breakdown de audiência (ouro para IA)
            $this->saveAudienceBreakdown(
                $integration,
                $mapping,
                $metaAds,
                $dateStr
            );

            Log::info('FetchMetaAdsMetricsJob: processado', [
                'car_id'      => $mapping->car_id,
                'company_id'  => $mapping->company_id,
                'spend'       => $spend,
                'impressions' => $impressions,
                'clicks'      => $clicks,
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

    private function saveAudienceBreakdown(
        CompanyIntegration $integration,
        CarAdCampaign $mapping,
        MetaAdsService $metaAds,
        string $dateStr
    ): void {
        $breakdown = $metaAds->getAudienceBreakdown(
            $integration->access_token,
            $mapping->external_id,
            $dateStr,
            $dateStr
        );

        foreach ($breakdown as $row) {
            $splitFactor = $mapping->spend_split_pct / 100;

            MetaAudienceInsight::updateOrCreate(
                [
                    'company_id'   => $mapping->company_id,
                    'car_id'       => $mapping->car_id,
                    'period_start' => $dateStr,
                    'period_end'   => $dateStr,
                    'age_range'    => $row['age']    ?? 'unknown',
                    'gender'       => $row['gender'] ?? 'unknown',
                ],
                [
                    'impressions' => (int) round((float) ($row['impressions'] ?? 0) * $splitFactor),
                    'clicks'      => (int) round((float) ($row['clicks']      ?? 0) * $splitFactor),
                    'spend'       => round((float) ($row['spend']       ?? 0) * $splitFactor, 2),
                    'reach'       => (int) round((float) ($row['reach']        ?? 0) * $splitFactor),
                ]
            );
        }
    }
}
