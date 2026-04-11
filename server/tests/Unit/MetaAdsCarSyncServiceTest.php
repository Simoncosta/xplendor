<?php

namespace Tests\Unit;

use App\Models\CarAdCampaign;
use App\Services\MetaAdsCarSyncService;
use App\Services\MetaAdsService;
use App\Services\MetaAdsTargetResolver;
use Illuminate\Support\Collection;
use Mockery;
use Tests\TestCase;

class MetaAdsCarSyncServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_keeps_single_mapping_at_full_weight(): void
    {
        $service = $this->makeService();
        $weights = $service->resolveNormalizedWeights(collect([
            $this->makeMapping(1, 10, 'adset_1', 'ad_1', 100),
        ]));

        $this->assertSame(1.0, $weights->get(1));
    }

    public function test_it_splits_one_car_with_three_ads_in_same_adset_evenly(): void
    {
        $service = $this->makeService();
        $weights = $service->resolveNormalizedWeights(collect([
            $this->makeMapping(1, 10, 'adset_1', 'ad_1', 100),
            $this->makeMapping(2, 10, 'adset_1', 'ad_2', 100),
            $this->makeMapping(3, 10, 'adset_1', 'ad_3', 100),
        ]));

        $this->assertEqualsWithDelta(0.333333, $weights->get(1), 0.000001);
        $this->assertEqualsWithDelta(0.333333, $weights->get(2), 0.000001);
        $this->assertEqualsWithDelta(0.333333, $weights->get(3), 0.000001);
    }

    public function test_it_splits_same_adset_across_cars_proportionally(): void
    {
        $service = $this->makeService();
        $weights = $service->resolveNormalizedWeights(collect([
            $this->makeMapping(1, 10, 'adset_1', 'ad_1', 100),
            $this->makeMapping(2, 10, 'adset_1', 'ad_2', 100),
            $this->makeMapping(3, 10, 'adset_1', 'ad_3', 100),
            $this->makeMapping(4, 11, 'adset_1', 'ad_4', 100),
        ]));

        $this->assertEqualsWithDelta(0.25, $weights->get(1), 0.000001);
        $this->assertEqualsWithDelta(0.25, $weights->get(2), 0.000001);
        $this->assertEqualsWithDelta(0.25, $weights->get(3), 0.000001);
        $this->assertEqualsWithDelta(0.25, $weights->get(4), 0.000001);
    }

    private function makeService(): MetaAdsCarSyncService
    {
        return new MetaAdsCarSyncService(
            Mockery::mock(MetaAdsService::class),
            Mockery::mock(MetaAdsTargetResolver::class),
        );
    }

    private function makeMapping(int $id, int $carId, string $adsetId, string $adId, float $weight): CarAdCampaign
    {
        $mapping = new CarAdCampaign([
            'company_id' => 1,
            'car_id' => $carId,
            'platform' => 'meta',
            'campaign_id' => 'campaign_1',
            'adset_id' => $adsetId,
            'ad_id' => $adId,
            'spend_split_pct' => $weight,
            'is_active' => true,
        ]);

        $mapping->id = $id;

        return $mapping;
    }
}
