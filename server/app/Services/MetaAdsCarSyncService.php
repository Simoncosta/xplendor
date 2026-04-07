<?php

namespace App\Services;

use App\Models\Car;
use App\Models\CarAdCampaign;
use App\Models\CarPerformanceMetric;
use App\Models\CompanyIntegration;
use App\Models\MetaAudienceInsight;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class MetaAdsCarSyncService
{
    public function __construct(
        protected MetaAdsService $metaAdsService,
    ) {}

    public function refreshCar(Car $car, ?Carbon $date = null): array
    {
        $date ??= now();
        $dateStr = $date->toDateString();

        $integration = CompanyIntegration::active()
            ->platform('meta')
            ->where('company_id', $car->company_id)
            ->first();

        if (!$integration) {
            throw new \DomainException('A empresa nao tem uma integracao Meta Ads ativa.');
        }

        if ($integration->isTokenExpired()) {
            $integration->update(['status' => 'expired']);
            throw new \DomainException('O token da integracao Meta Ads expirou. Reconecta a conta para continuar.');
        }

        $mappings = CarAdCampaign::active()
            ->platform('meta')
            ->where('company_id', $car->company_id)
            ->where('car_id', $car->id)
            ->get();

        if ($mappings->isEmpty()) {
            throw new \DomainException('Esta viatura ainda nao tem uma campanha Meta Ads associada.');
        }

        $processed = [];
        $errors = [];

        foreach ($mappings as $mapping) {
            try {
                $processed[] = $this->refreshMappingForDay($integration, $mapping, $dateStr);
            } catch (\Throwable $exception) {
                $errors[] = [
                    'mapping_id' => $mapping->id,
                    'message' => $exception->getMessage(),
                ];

                Log::warning('MetaAdsCarSyncService: falha ao refrescar mapeamento', [
                    'company_id' => $car->company_id,
                    'car_id' => $car->id,
                    'mapping_id' => $mapping->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if (empty($processed)) {
            throw new \RuntimeException($errors[0]['message'] ?? 'Nao foi possivel atualizar os dados Meta Ads desta viatura.');
        }

        $integration->update(['last_synced_at' => now(), 'error_message' => null]);

        return [
            'car_id' => $car->id,
            'date' => $dateStr,
            'processed_mappings' => count($processed),
            'metrics_updated' => count(array_filter($processed, fn($item) => (bool) ($item['metric_updated'] ?? false))),
            'targeting_updated' => count(array_filter($processed, fn($item) => (bool) ($item['targeting_updated'] ?? false))),
            'targeting_status' => $this->resolveTargetingStatus($processed),
            'mappings' => $processed,
            'errors' => $errors,
        ];
    }

    public function refreshMappingForDay(
        CompanyIntegration $integration,
        CarAdCampaign $mapping,
        string $dateStr
    ): array {
        $resolvedAdsetId = $this->resolveAdsetId($mapping);

        $insights = $this->metaAdsService->getAdsetInsights(
            $integration->access_token,
            $mapping->external_id,
            $dateStr,
            $dateStr
        );

        $splitFactor = $this->resolveAllocationFactor($mapping);
        $spend = round((float) ($insights['spend'] ?? 0) * $splitFactor, 2);
        $impressions = (int) round((float) ($insights['impressions'] ?? 0) * $splitFactor);
        $clicks = (int) round((float) ($insights['clicks'] ?? 0) * $splitFactor);

        $metric = CarPerformanceMetric::firstOrCreate(
            [
                'car_id' => $mapping->car_id,
                'company_id' => $mapping->company_id,
                'channel' => 'paid',
                'period_start' => $dateStr,
                'period_end' => $dateStr,
            ],
            [
                'sessions' => 0,
                'leads_count' => 0,
                'interactions_count' => 0,
                'spend_amount' => 0,
                'impressions' => 0,
                'clicks' => 0,
                'data_source' => 'meta_ads',
            ]
        );

        $metric->update([
            'spend_amount' => $spend,
            'impressions' => $impressions,
            'clicks' => $clicks,
            'data_source' => 'meta_ads',
        ]);

        $metric->recalculate();

        $targetingResult = $this->saveAudienceBreakdown(
            $integration,
            $mapping,
            $dateStr,
            $resolvedAdsetId
        );

        return [
            'mapping_id' => $mapping->id,
            'mapping_level' => $mapping->level,
            'external_id_used_for_metrics' => $mapping->external_id,
            'resolved_adset_id' => $resolvedAdsetId,
            'metric_updated' => true,
            'targeting_updated' => (bool) ($targetingResult['updated'] ?? false),
            'has_targeting' => (bool) ($targetingResult['has_targeting'] ?? false),
            'has_breakdown' => (bool) ($targetingResult['has_breakdown'] ?? false),
            'persisted_insight_ids' => $targetingResult['persisted_insight_ids'] ?? [],
        ];
    }

    private function saveAudienceBreakdown(
        CompanyIntegration $integration,
        CarAdCampaign $mapping,
        string $dateStr,
        ?string $resolvedAdsetId
    ): array {
        if (!$resolvedAdsetId) {
            Log::info('MetaAdsCarSyncService: campaign without resolvable adset for targeting sync', [
                'company_id' => $mapping->company_id,
                'car_id' => $mapping->car_id,
                'mapping_id' => $mapping->id,
                'mapping_level' => $mapping->level,
                'campaign_id' => $mapping->campaign_id,
                'adset_id' => $mapping->adset_id,
                'external_id' => $mapping->external_id,
            ]);

            return [
                'updated' => false,
                'has_targeting' => false,
                'has_breakdown' => false,
                'persisted_insight_ids' => [],
            ];
        }

        $targetingDetails = $this->metaAdsService->getAdsetTargetingDetails(
            $integration->access_token,
            $resolvedAdsetId,
        );
        $campaignTargeting = $targetingDetails['normalized_targeting'] ?? [];
        $hasTargeting = (bool) ($targetingDetails['has_targeting'] ?? false);

        $breakdown = $this->metaAdsService->getAudienceBreakdown(
            $integration->access_token,
            $resolvedAdsetId,
            $dateStr,
            $dateStr
        );
        $hasBreakdown = !empty($breakdown);

        if (empty($breakdown) && empty($campaignTargeting)) {
            Log::info('MetaAdsCarSyncService: targeting unavailable for mapping', [
                'company_id' => $mapping->company_id,
                'car_id' => $mapping->car_id,
                'mapping_id' => $mapping->id,
                'resolved_adset_id' => $resolvedAdsetId,
                'has_targeting' => $hasTargeting,
            ]);

            return [
                'updated' => false,
                'has_targeting' => $hasTargeting,
                'has_breakdown' => $hasBreakdown,
                'persisted_insight_ids' => [],
            ];
        }

        if (empty($breakdown)) {
            $insight = MetaAudienceInsight::updateOrCreate(
                [
                    'company_id' => $mapping->company_id,
                    'car_id' => $mapping->car_id,
                    'period_start' => $dateStr,
                    'period_end' => $dateStr,
                    'age_range' => 'unknown',
                    'gender' => 'unknown',
                ],
                [
                    'impressions' => 0,
                    'clicks' => 0,
                    'spend' => 0,
                    'reach' => 0,
                    'campaign_targeting_json' => $campaignTargeting,
                ]
            );

            Log::info('MetaAdsCarSyncService: targeting persisted without breakdown rows', [
                'company_id' => $mapping->company_id,
                'car_id' => $mapping->car_id,
                'mapping_id' => $mapping->id,
                'resolved_adset_id' => $resolvedAdsetId,
                'has_targeting' => $hasTargeting,
                'insight_id' => $insight->id,
                'age_range' => $insight->age_range,
                'gender' => $insight->gender,
                'campaign_targeting_json' => $insight->campaign_targeting_json,
            ]);

            return [
                'updated' => true,
                'has_targeting' => $hasTargeting,
                'has_breakdown' => false,
                'persisted_insight_ids' => [$insight->id],
            ];
        }

        $persistedInsightIds = [];
        $splitFactor = $this->resolveAllocationFactor($mapping);

        foreach ($breakdown as $row) {
            $insight = MetaAudienceInsight::updateOrCreate(
                [
                    'company_id' => $mapping->company_id,
                    'car_id' => $mapping->car_id,
                    'period_start' => $dateStr,
                    'period_end' => $dateStr,
                    'age_range' => $row['age'] ?? 'unknown',
                    'gender' => $row['gender'] ?? 'unknown',
                ],
                [
                    'impressions' => (int) round((float) ($row['impressions'] ?? 0) * $splitFactor),
                    'clicks' => (int) round((float) ($row['clicks'] ?? 0) * $splitFactor),
                    'spend' => round((float) ($row['spend'] ?? 0) * $splitFactor, 2),
                    'reach' => (int) round((float) ($row['reach'] ?? 0) * $splitFactor),
                    'campaign_targeting_json' => !empty($campaignTargeting) ? $campaignTargeting : null,
                ]
            );

            $persistedInsightIds[] = $insight->id;
        }

        Log::info('MetaAdsCarSyncService: targeting persisted with audience breakdown', [
            'company_id' => $mapping->company_id,
            'car_id' => $mapping->car_id,
            'mapping_id' => $mapping->id,
            'resolved_adset_id' => $resolvedAdsetId,
            'has_targeting' => $hasTargeting,
            'persisted_insight_ids' => $persistedInsightIds,
        ]);

        return [
            'updated' => true,
            'has_targeting' => $hasTargeting,
            'has_breakdown' => true,
            'persisted_insight_ids' => $persistedInsightIds,
        ];
    }

    private function resolveTargetingStatus(array $processed): string
    {
        $hasResolvedAdset = collect($processed)->contains(fn($item) => !empty($item['resolved_adset_id']));
        $hasTargeting = collect($processed)->contains(fn($item) => !empty($item['has_targeting']));

        if (!$hasResolvedAdset) {
            return 'campaign_without_adset';
        }

        return $hasTargeting ? 'available' : 'unavailable';
    }

    private function resolveAdsetId(CarAdCampaign $mapping): ?string
    {
        if (!empty($mapping->adset_id)) {
            return $mapping->adset_id;
        }

        if ($mapping->level === 'adset' && !empty($mapping->external_id)) {
            return $mapping->external_id;
        }

        return null;
    }

    private function resolveAllocationFactor(CarAdCampaign $mapping): float
    {
        if (empty($mapping->adset_id)) {
            return 1.0;
        }

        $totalWeight = (float) CarAdCampaign::query()
            ->active()
            ->where('company_id', $mapping->company_id)
            ->where('platform', $mapping->platform)
            ->where('adset_id', $mapping->adset_id)
            ->sum('spend_split_pct');

        if ($totalWeight <= 0) {
            return 1.0;
        }

        return (float) $mapping->spend_split_pct / $totalWeight;
    }
}
