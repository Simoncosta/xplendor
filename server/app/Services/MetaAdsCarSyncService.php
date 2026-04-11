<?php

namespace App\Services;

use App\Models\Car;
use App\Models\CarAdCampaign;
use App\Models\CampaignCarMetricDaily;
use App\Models\CarPerformanceMetric;
use App\Models\CompanyIntegration;
use App\Models\MetaAudienceInsight;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class MetaAdsCarSyncService
{
    public function __construct(
        protected MetaAdsService $metaAdsService,
        protected MetaAdsTargetResolver $targetResolver,
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
        $normalizedWeights = $this->resolveNormalizedWeights(
            $this->loadNormalizationPeerMappings($mappings)
        );

        foreach ($mappings as $mapping) {
            try {
                $processed[] = $this->refreshMappingForDay(
                    $integration,
                    $mapping,
                    $dateStr,
                    (float) ($normalizedWeights->get($mapping->id) ?? 1.0)
                );
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
        string $dateStr,
        ?float $allocationFactor = null
    ): array {
        $resolvedTarget = $this->targetResolver->resolveMetaTarget($mapping);
        $resolvedAdsetId = $this->targetResolver->resolveAdsetId($mapping);

        $insights = $this->metaAdsService->getInsights(
            $integration->access_token,
            $resolvedTarget['id'],
            $resolvedTarget['level'],
            $dateStr,
            $dateStr
        );

        $splitFactor = $allocationFactor ?? $this->resolveNormalizedWeight($mapping);
        $spend = round((float) ($insights['spend'] ?? 0) * $splitFactor, 2);
        $impressions = (int) round((float) ($insights['impressions'] ?? 0) * $splitFactor);
        $clicks = (int) round((float) ($insights['clicks'] ?? 0) * $splitFactor);
        $this->persistCampaignMetricDaily(
            $mapping,
            $dateStr,
            $splitFactor,
            $impressions,
            $clicks,
            $spend
        );

        $dailyAggregate = CampaignCarMetricDaily::query()
            ->where('company_id', $mapping->company_id)
            ->where('car_id', $mapping->car_id)
            ->whereDate('date', $dateStr)
            ->selectRaw('
                COALESCE(SUM(impressions), 0) as impressions,
                COALESCE(SUM(clicks), 0) as clicks,
                COALESCE(SUM(spend_normalized), 0) as spend
            ')
            ->first();

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
            'spend_amount' => round((float) ($dailyAggregate->spend ?? 0), 2),
            'impressions' => (int) ($dailyAggregate->impressions ?? 0),
            'clicks' => (int) ($dailyAggregate->clicks ?? 0),
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
            'mapping_level' => $resolvedTarget['level'],
            'external_id_used_for_metrics' => $resolvedTarget['id'],
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
                'mapping_level' => $this->targetResolver->resolveMetaTarget($mapping)['level'],
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
        $splitFactor = $this->resolveNormalizedWeight($mapping);

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

    public function resolveNormalizedWeights(Collection $mappings): Collection
    {
        if ($mappings->isEmpty()) {
            return collect();
        }

        $normalized = collect();

        $mappings
            ->groupBy(fn(CarAdCampaign $mapping) => $this->buildNormalizationScopeKey($mapping))
            ->each(function (Collection $scopeMappings) use ($normalized) {
                if ($scopeMappings->count() === 1) {
                    $mapping = $scopeMappings->first();
                    $normalized->put($mapping->id, 1.0);

                    return;
                }

                $carGroups = $scopeMappings->groupBy(fn(CarAdCampaign $mapping) => (string) $mapping->car_id);
                $carWeightTotals = $carGroups->map(
                    fn(Collection $carMappings) => (float) $carMappings
                        ->sum(fn(CarAdCampaign $item) => max(0.0, (float) ($item->spend_split_pct ?? 0)))
                );
                $scopeTotalWeight = (float) $carWeightTotals->sum();

                $carGroups->each(function (Collection $carMappings, string $carId) use ($carWeightTotals, $scopeTotalWeight, $normalized) {
                    $carWeight = (float) ($carWeightTotals->get($carId) ?? 0.0);
                    $carShare = $scopeTotalWeight > 0
                        ? $carWeight / $scopeTotalWeight
                        : (1 / max($carWeightTotals->count(), 1));

                    $mappingTotalWeight = (float) $carMappings
                        ->sum(fn(CarAdCampaign $item) => max(0.0, (float) ($item->spend_split_pct ?? 0)));

                    $carMappings->each(function (CarAdCampaign $mapping) use ($carMappings, $carShare, $mappingTotalWeight, $normalized) {
                        $mappingWeight = max(0.0, (float) ($mapping->spend_split_pct ?? 0));
                        $intraCarShare = $mappingTotalWeight > 0
                            ? $mappingWeight / $mappingTotalWeight
                            : (1 / max($carMappings->count(), 1));

                        $normalized->put($mapping->id, round($carShare * $intraCarShare, 6));
                    });
                });
            });

        return $normalized;
    }

    private function persistCampaignMetricDaily(
        CarAdCampaign $mapping,
        string $dateStr,
        float $splitFactor,
        int $impressions,
        int $clicks,
        float $spend
    ): void {
        $snapshot = $this->buildCampaignMetricSnapshot($impressions, $clicks, $spend);

        CampaignCarMetricDaily::updateOrCreate(
            [
                'mapping_id' => $mapping->id,
                'date' => $dateStr,
            ],
            [
                'company_id' => $mapping->company_id,
                'car_id' => $mapping->car_id,
                'campaign_id' => $mapping->campaign_id,
                'adset_id' => $mapping->adset_id,
                'impressions' => $impressions,
                'clicks' => $clicks,
                'spend_normalized' => $spend,
                'ctr' => $snapshot['ctr'],
                'cpc' => $snapshot['cpc'],
                'cpm' => $snapshot['cpm'],
                'allocation_factor' => round($splitFactor, 6),
            ]
        );
    }

    private function resolveNormalizedWeight(CarAdCampaign $mapping): float
    {
        $normalized = $this->resolveNormalizedWeights(
            $this->loadNormalizationPeerMappings(collect([$mapping]))
        );

        return (float) ($normalized->get($mapping->id) ?? 1.0);
    }

    private function loadNormalizationPeerMappings(Collection $mappings): Collection
    {
        if ($mappings->isEmpty()) {
            return collect();
        }

        $scopeQueries = $mappings
            ->map(fn(CarAdCampaign $mapping) => [
                'company_id' => $mapping->company_id,
                'platform' => $mapping->platform,
                'adset_id' => $mapping->adset_id,
                'campaign_id' => $mapping->campaign_id,
            ])
            ->unique(fn(array $scope) => implode('|', [
                $scope['company_id'],
                $scope['platform'],
                $scope['adset_id'] ?? 'null',
                $scope['campaign_id'] ?? 'null',
            ]))
            ->values();

        return CarAdCampaign::query()
            ->active()
            ->where(function ($query) use ($scopeQueries) {
                foreach ($scopeQueries as $scope) {
                    $query->orWhere(function ($subQuery) use ($scope) {
                        $subQuery
                            ->where('company_id', $scope['company_id'])
                            ->where('platform', $scope['platform']);

                        if (!empty($scope['adset_id'])) {
                            $subQuery->where('adset_id', $scope['adset_id']);
                        } else {
                            $subQuery
                                ->whereNull('adset_id')
                                ->where('campaign_id', $scope['campaign_id']);
                        }
                    });
                }
            })
            ->get(['id', 'company_id', 'car_id', 'platform', 'campaign_id', 'adset_id', 'spend_split_pct']);
    }

    private function buildNormalizationScopeKey(CarAdCampaign $mapping): string
    {
        if (!empty($mapping->adset_id)) {
            return implode(':', [
                'adset',
                $mapping->company_id,
                $mapping->platform,
                $mapping->adset_id,
            ]);
        }

        return implode(':', [
            'campaign',
            $mapping->company_id,
            $mapping->platform,
            $mapping->campaign_id,
        ]);
    }

    private function buildCampaignMetricSnapshot(int $impressions, int $clicks, float $spend): array
    {
        return [
            'ctr' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : null,
            'cpc' => $clicks > 0 ? round($spend / $clicks, 4) : null,
            'cpm' => $impressions > 0 ? round(($spend / $impressions) * 1000, 2) : null,
        ];
    }
}
