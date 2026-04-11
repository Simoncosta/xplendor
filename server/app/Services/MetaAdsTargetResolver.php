<?php

namespace App\Services;

use App\Models\CarAdCampaign;
use Illuminate\Support\Collection;

class MetaAdsTargetResolver
{
    public function __construct(
        protected MetaAdsService $metaAdsService,
    ) {}

    public function resolveMetaTarget(CarAdCampaign $mapping): array
    {
        $level = $this->resolveLevel(
            $mapping->campaign_id,
            $mapping->adset_id,
            $mapping->ad_id
        );

        return [
            'level' => $level,
            'id' => match ($level) {
                'ad' => (string) $mapping->ad_id,
                'adset' => (string) $mapping->adset_id,
                default => (string) $mapping->campaign_id,
            },
        ];
    }

    public function resolveLevel(?string $campaignId, ?string $adsetId, ?string $adId): string
    {
        if (!empty($adId)) {
            return 'ad';
        }

        if (!empty($adsetId)) {
            return 'adset';
        }

        return 'campaign';
    }

    public function resolveAdsetId(?CarAdCampaign $mapping): ?string
    {
        if (!$mapping) {
            return null;
        }

        return !empty($mapping->adset_id) ? (string) $mapping->adset_id : null;
    }

    public function getActiveMappingsForCar(int $companyId, int $carId, string $platform = 'meta'): Collection
    {
        $mappings = CarAdCampaign::query()
            ->where('company_id', $companyId)
            ->where('car_id', $carId)
            ->where('platform', $platform)
            ->where('is_active', true)
            ->orderByDesc('created_at')
            ->get();

        $describedMappings = $mappings->map(fn(CarAdCampaign $mapping) => $this->describeMapping($mapping));
        $metaStatuses = $this->metaAdsService->getTargetStatuses(
            $companyId,
            $describedMappings
                ->map(fn(array $mapping) => [
                    'id' => (string) ($mapping['target_id'] ?? ''),
                    'level' => (string) ($mapping['level'] ?? ''),
                ])
                ->all()
        );

        return $describedMappings
            ->map(function (array $mapping) use ($metaStatuses) {
                $metaStatus = $metaStatuses[$mapping['level'] . ':' . $mapping['target_id']] ?? 'unknown';
                $mapping['meta_status'] = $metaStatus;

                return $mapping;
            })
            ->filter(fn(array $mapping) => ($mapping['is_active'] ?? false) === true
                && in_array($mapping['meta_status'], ['ACTIVE', 'unknown'], true))
            ->values();
    }

    public function describeMapping(CarAdCampaign $mapping): array
    {
        $target = $this->resolveMetaTarget($mapping);
        $affectedCarsCount = $this->resolveAffectedCarsCount($mapping, $target['level']);

        return [
            'mapping_id' => $mapping->id,
            'is_active' => (bool) $mapping->is_active,
            'level' => $target['level'],
            'target_id' => $target['id'],
            'campaign_id' => $mapping->campaign_id,
            'adset_id' => $mapping->adset_id,
            'ad_id' => $mapping->ad_id,
            'campaign_name' => $mapping->campaign_name,
            'adset_name' => $mapping->adset_name,
            'ad_name' => $mapping->ad_name,
            'shared_with_other_cars' => $affectedCarsCount > 1,
            'affected_cars_count' => $affectedCarsCount,
        ];
    }

    private function resolveAffectedCarsCount(CarAdCampaign $mapping, string $level): int
    {
        $query = CarAdCampaign::query()
            ->where('company_id', $mapping->company_id)
            ->where('platform', $mapping->platform)
            ->where('is_active', true);

        $query = match ($level) {
            'ad' => $query->where('ad_id', $mapping->ad_id),
            'adset' => $query->where('adset_id', $mapping->adset_id),
            default => $query->where('campaign_id', $mapping->campaign_id),
        };

        return (int) $query
            ->distinct('car_id')
            ->count('car_id');
    }
}
