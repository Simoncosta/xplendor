<?php

namespace Tests\Feature;

use App\Models\Car;
use App\Services\NextBestCarToPromoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class NextBestCarToPromoteServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ranks_cars_into_the_four_promotion_states(): void
    {
        [$companyId, $brandId, $modelId, $categoryId] = $this->createCompanyContext();

        $ready = $this->createCar($companyId, $brandId, $modelId, $categoryId, 'Ready', 45);
        $candidate = $this->createCar($companyId, $brandId, $modelId, $categoryId, 'Candidate', 45);
        $watch = $this->createCar($companyId, $brandId, $modelId, $categoryId, 'Watch', 12);
        $avoid = $this->createCar($companyId, $brandId, $modelId, $categoryId, 'Avoid', 160);

        $this->seedPotentialScore($companyId, $ready->id, 90, -2);
        $this->seedPotentialScore($companyId, $candidate->id, 70, 2);
        $this->seedPotentialScore($companyId, $watch->id, 50, 2);
        $this->seedPotentialScore($companyId, $avoid->id, 25, 18);

        $this->seedViews($companyId, $ready->id, 80, 20);
        $this->seedInteractions($companyId, $ready->id, 'whatsapp_click', 2);
        $this->seedInteractions($companyId, $ready->id, 'call_click', 1);

        $this->seedViews($companyId, $candidate->id, 40, 10);
        $this->seedInteractions($companyId, $candidate->id, 'whatsapp_click', 1);
        $this->seedInteractions($companyId, $candidate->id, 'call_click', 1);

        $this->seedViews($companyId, $watch->id, 4, 2);

        $this->seedActiveCampaign($companyId, $avoid->id, 'cmp_avoid', 40);

        $ranking = app(NextBestCarToPromoteService::class)->rankCarsForPromotion($companyId);
        $states = collect($ranking['cars'])->pluck('promotion_state', 'car_id');

        $this->assertSame('ready', $states[$ready->id]);
        $this->assertSame('candidate', $states[$candidate->id]);
        $this->assertSame('watch', $states[$watch->id]);
        $this->assertSame('avoid', $states[$avoid->id]);
        $this->assertSame(1, $ranking['summary']['ready']);
        $this->assertSame(1, $ranking['summary']['candidate']);
        $this->assertSame(1, $ranking['summary']['watch']);
        $this->assertSame(1, $ranking['summary']['avoid']);
    }

    public function test_test_campaign_seed_never_enters_avoid(): void
    {
        [$companyId, $brandId, $modelId, $categoryId] = $this->createCompanyContext();
        $car = $this->createCar($companyId, $brandId, $modelId, $categoryId, 'Seed', 20);

        $this->seedPotentialScore($companyId, $car->id, 50, 1);
        $this->seedViews($companyId, $car->id, 2, 1);

        $ranking = app(NextBestCarToPromoteService::class)->rankCarsForPromotion($companyId);
        $rankedCar = collect($ranking['cars'])->firstWhere('car_id', $car->id);

        $this->assertNotSame('avoid', $rankedCar['promotion_state']);
        $this->assertContains('test_campaign_seed', $rankedCar['flags']);
        $this->assertSame('test_campaign_seed', $rankedCar['recommended_action']['type']);
    }

    public function test_suggests_investment_switch_when_active_car_is_weak_and_better_candidate_exists(): void
    {
        [$companyId, $brandId, $modelId, $categoryId] = $this->createCompanyContext();

        $weakActive = $this->createCar($companyId, $brandId, $modelId, $categoryId, 'Weak Active', 140);
        $better = $this->createCar($companyId, $brandId, $modelId, $categoryId, 'Better', 35);

        $this->seedPotentialScore($companyId, $weakActive->id, 20, 20);
        $this->seedPotentialScore($companyId, $better->id, 92, -4);
        $this->seedActiveCampaign($companyId, $weakActive->id, 'cmp_weak', 45);

        $this->seedViews($companyId, $better->id, 100, 25);
        $this->seedInteractions($companyId, $better->id, 'whatsapp_click', 3);
        $this->seedInteractions($companyId, $better->id, 'call_click', 1);

        $switch = app(NextBestCarToPromoteService::class)->suggestInvestmentSwitch($companyId);

        $this->assertNotNull($switch);
        $this->assertSame($weakActive->id, $switch['from_car_id']);
        $this->assertSame($better->id, $switch['to_car_id']);
        $this->assertSame('switch_car_investment', $switch['action']['type']);
        $this->assertGreaterThanOrEqual(55, $switch['confidence']);
    }

    private function createCompanyContext(): array
    {
        $planId = DB::table('plans')->insertGetId([
            'name' => 'Test Plan',
            'description' => 'Plan',
            'price' => 99,
            'car_limit' => 30,
            'features' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $companyId = DB::table('companies')->insertGetId([
            'nipc' => (string) random_int(100000000, 999999999),
            'fiscal_name' => 'Promotion Test Company',
            'trade_name' => 'Promotion Test Company',
            'public_api_token' => (string) Str::uuid(),
            'plan_id' => $planId,
            'lead_distribution' => 'manual',
            'registration_fees' => 0,
            'export_promotion_price' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $brandId = DB::table('car_brands')->insertGetId([
            'name' => 'BMW',
            'slug' => 'bmw',
            'vehicle_type' => 'car',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $modelId = DB::table('car_models')->insertGetId([
            'name' => '320d',
            'type' => 'other',
            'vehicle_type' => 'car',
            'car_brand_id' => $brandId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $categoryId = DB::table('car_categories')->insertGetId([
            'name' => 'Sedan',
            'slug' => 'sedan',
            'vehicle_type' => 'car',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$companyId, $brandId, $modelId, $categoryId];
    }

    private function createCar(int $companyId, int $brandId, int $modelId, int $categoryId, string $version, int $daysInStock): Car
    {
        return Car::factory()->create([
            'company_id' => $companyId,
            'car_brand_id' => $brandId,
            'car_model_id' => $modelId,
            'car_category_id' => $categoryId,
            'version' => $version,
            'segment' => 'sedan',
            'price_gross' => 24900,
            'created_at' => now()->subDays($daysInStock),
            'car_created_at' => now()->subDays($daysInStock),
        ]);
    }

    private function seedPotentialScore(int $companyId, int $carId, int $score, float $priceVsMarket): void
    {
        DB::table('car_sale_potential_scores')->insert([
            'company_id' => $companyId,
            'car_id' => $carId,
            'score' => $score,
            'classification' => $score >= 70 ? 'hot' : ($score >= 40 ? 'warm' : 'cold'),
            'score_breakdown' => json_encode([]),
            'price_vs_market' => $priceVsMarket,
            'days_in_stock_at_calc' => 40,
            'calculated_at' => now(),
            'triggered_by' => 'manual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedViews(int $companyId, int $carId, int $count, int $sessions): void
    {
        for ($i = 0; $i < $count; $i++) {
            DB::table('car_views')->insert([
                'company_id' => $companyId,
                'car_id' => $carId,
                'ip_address' => "127.0.0.{$i}",
                'visitor_id' => (string) Str::uuid(),
                'session_id' => 'session_' . ($i % max(1, $sessions)),
                'view_duration_seconds' => 45,
                'created_at' => now()->subDays($i % 5),
                'updated_at' => now()->subDays($i % 5),
            ]);
        }
    }

    private function seedInteractions(int $companyId, int $carId, string $type, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            DB::table('car_interactions')->insert([
                'company_id' => $companyId,
                'car_id' => $carId,
                'interaction_type' => $type,
                'visitor_id' => (string) Str::uuid(),
                'session_id' => (string) Str::uuid(),
                'created_at' => now()->subDays($i % 3),
                'updated_at' => now()->subDays($i % 3),
            ]);
        }
    }

    private function seedActiveCampaign(int $companyId, int $carId, string $campaignId, float $spend): void
    {
        $mappingId = DB::table('car_ad_campaigns')->insertGetId([
            'company_id' => $companyId,
            'car_id' => $carId,
            'platform' => 'meta',
            'campaign_id' => $campaignId,
            'campaign_name' => $campaignId,
            'adset_id' => $campaignId . '_adset',
            'adset_name' => $campaignId . ' adset',
            'ad_id' => null,
            'ad_name' => null,
            'level' => 'adset',
            'spend_split_pct' => 100,
            'is_active' => true,
            'created_at' => now()->subDays(3),
            'updated_at' => now(),
        ]);

        DB::table('campaign_car_metrics_daily')->insert([
            'company_id' => $companyId,
            'car_id' => $carId,
            'mapping_id' => $mappingId,
            'campaign_id' => $campaignId,
            'adset_id' => $campaignId . '_adset',
            'date' => now()->toDateString(),
            'impressions' => 1000,
            'clicks' => 10,
            'spend_normalized' => $spend,
            'ctr' => 1,
            'cpc' => 1,
            'cpm' => 10,
            'allocation_factor' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
