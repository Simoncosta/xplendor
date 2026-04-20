<?php

namespace Tests\Feature;

use App\Models\Car;
use App\Models\Company;
use App\Services\SmartAdsOptimizerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SmartAdsOptimizerServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_cut_recommendation_for_high_spend_low_intent(): void
    {
        [$company, $car] = $this->createCompanyAndCar();
        $mappingId = $this->createMapping($company->id, $car->id, 'cmp_cut', 'adset_cut', 'ad_cut');
        $this->createPerformanceRow($company->id, $car->id, $mappingId, 'cmp_cut', 'adset_cut', 1000, 10, 80);
        $this->createAttributionRow($company->id, $car->id, 'cmp_cut', 'adset_cut', 'ad_cut', 0, 0, 0);

        $recommendations = app(SmartAdsOptimizerService::class)->generateRecommendationsForCar(
            $car,
            $this->analysis(1000, 10, 80, 1.0),
            $this->intelligence(20, 0, 0, 0),
            ['primary_gap_state' => 'no_real_interest'],
            [['type' => 'high_spend_low_intent']],
            'PARAR'
        );

        $this->assertSame('pause_ad', $recommendations['cut'][0]['type']);
        $this->assertSame('ad_cut', $recommendations['cut'][0]['target_id']);
        $this->assertSame('Alto gasto sem intenção', $recommendations['cut'][0]['reason']);
        $this->assertSame(80.0, $recommendations['cut'][0]['data']['spend']);
        $this->assertSame('high', $recommendations['cut'][0]['impact']['urgency']);
        $this->assertSame(80.0, $recommendations['cut'][0]['impact']['estimated_loss']);
        $this->assertNotEmpty($recommendations['cut'][0]['why']);
        $this->assertNotEmpty($recommendations['cut'][0]['next_step']);
        $this->assertSame('pause_campaign', $recommendations['cut'][0]['action_key']);
        $this->assertSame('pause_ad', $recommendations['primary_action']['type']);
    }

    public function test_generates_scale_recommendation_for_high_intent_with_leads(): void
    {
        [$company, $car] = $this->createCompanyAndCar();
        $mappingId = $this->createMapping($company->id, $car->id, 'cmp_scale', 'adset_scale', null);
        $this->createPerformanceRow($company->id, $car->id, $mappingId, 'cmp_scale', 'adset_scale', 1200, 30, 45);
        $this->createAttributionRow($company->id, $car->id, 'cmp_scale', 'adset_scale', null, 2, 1, 3);

        $recommendations = app(SmartAdsOptimizerService::class)->generateRecommendationsForCar(
            $car,
            $this->analysis(1200, 30, 45, 2.5),
            $this->intelligence(82, 2, 1, 3),
            ['primary_gap_state' => 'healthy_flow'],
            [],
            'ESCALAR'
        );

        $this->assertSame('scale_adset', $recommendations['scale'][0]['type']);
        $this->assertSame('adset_scale', $recommendations['scale'][0]['target_id']);
        $this->assertSame('Anúncio com intenção forte e potencial de escala', $recommendations['scale'][0]['reason']);
        $this->assertArrayHasKey('estimated_gain', $recommendations['scale'][0]['impact']);
        $this->assertContains($recommendations['scale'][0]['impact']['urgency'], ['medium', 'high']);
        $this->assertNotEmpty($recommendations['scale'][0]['next_step']);
        $this->assertSame('duplicate_campaign', $recommendations['scale'][0]['action_key']);
        $this->assertSame('scale_adset', $recommendations['primary_action']['type']);
    }

    public function test_generates_fix_recommendation_for_high_intent_without_leads(): void
    {
        [$company, $car] = $this->createCompanyAndCar();
        $mappingId = $this->createMapping($company->id, $car->id, 'cmp_fix', 'adset_fix', 'ad_fix');
        $this->createPerformanceRow($company->id, $car->id, $mappingId, 'cmp_fix', 'adset_fix', 900, 20, 30);
        $this->createAttributionRow($company->id, $car->id, 'cmp_fix', 'adset_fix', 'ad_fix', 2, 0, 4);

        $recommendations = app(SmartAdsOptimizerService::class)->generateRecommendationsForCar(
            $car,
            $this->analysis(900, 20, 30, 2.22),
            $this->intelligence(78, 2, 0, 4),
            ['primary_gap_state' => 'decision_friction'],
            [['type' => 'decision_friction']],
            'CORRIGIR'
        );

        $this->assertSame('improve_landing', $recommendations['fix'][0]['type']);
        $this->assertSame('ad_fix', $recommendations['fix'][0]['target_id']);
        $this->assertSame('Intenção alta sem conversão', $recommendations['fix'][0]['reason']);
        $this->assertNotEmpty($recommendations['fix'][0]['why']);
        $this->assertNotEmpty($recommendations['fix'][0]['next_step']);
        $this->assertNull($recommendations['fix'][0]['action_key']);
        $this->assertSame('improve_landing', $recommendations['primary_action']['type']);
    }

    public function test_generates_test_recommendation_for_low_ctr(): void
    {
        [$company, $car] = $this->createCompanyAndCar();
        $mappingId = $this->createMapping($company->id, $car->id, 'cmp_test', 'adset_test', 'ad_test');
        $this->createPerformanceRow($company->id, $car->id, $mappingId, 'cmp_test', 'adset_test', 1000, 5, 18);
        $this->createAttributionRow($company->id, $car->id, 'cmp_test', 'adset_test', 'ad_test', 0, 0, 0);

        $recommendations = app(SmartAdsOptimizerService::class)->generateRecommendationsForCar(
            $car,
            $this->analysis(1000, 5, 18, 0.5),
            $this->intelligence(25, 0, 0, 0),
            ['primary_gap_state' => 'no_real_interest'],
            [],
            'CORRIGIR'
        );

        $this->assertSame('test_creative', $recommendations['test'][0]['type']);
        $this->assertSame('ad_test', $recommendations['test'][0]['target_id']);
        $this->assertSame('low_ctr', $recommendations['test'][0]['based_on']);
        $this->assertSame('new angle highlighting price', $recommendations['test'][0]['hypothesis']);
        $this->assertSame('low', $recommendations['test'][0]['impact']['urgency']);
        $this->assertNotEmpty($recommendations['test'][0]['next_step']);
        $this->assertSame('generate_new_copy', $recommendations['test'][0]['action_key']);
        $this->assertSame('test_creative', $recommendations['primary_action']['type']);
    }

    private function analysis(int $impressions, int $clicks, float $spend, float $ctr): array
    {
        return [
            'period' => [
                'from' => now()->subDays(6)->toDateString(),
                'to' => now()->toDateString(),
            ],
            'metrics' => [
                'impressions' => $impressions,
                'clicks' => $clicks,
                'spend_normalized' => $spend,
                'ctr' => $ctr,
                'views' => 40,
                'sessions' => 12,
                'whatsapp_clicks' => 0,
                'form_opens' => 0,
                'leads' => 0,
            ],
        ];
    }

    private function intelligence(int $intentScore, int $strongIntentUsers, int $leads, int $whatsappClicks): array
    {
        return [
            'intent_score' => $intentScore,
            'strong_intent_users' => $strongIntentUsers,
            'leads' => $leads,
            'whatsapp_clicks' => $whatsappClicks,
            'confidence_score' => 82,
        ];
    }

    private function createMapping(int $companyId, int $carId, string $campaignId, ?string $adsetId, ?string $adId): int
    {
        return DB::table('car_ad_campaigns')->insertGetId([
            'company_id' => $companyId,
            'car_id' => $carId,
            'platform' => 'meta',
            'campaign_id' => $campaignId,
            'campaign_name' => Str::title(str_replace('_', ' ', $campaignId)),
            'adset_id' => $adsetId,
            'adset_name' => $adsetId ? Str::title(str_replace('_', ' ', $adsetId)) : null,
            'ad_id' => $adId,
            'ad_name' => $adId ? Str::title(str_replace('_', ' ', $adId)) : null,
            'level' => $adId ? 'ad' : ($adsetId ? 'adset' : 'campaign'),
            'spend_split_pct' => 100,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createPerformanceRow(
        int $companyId,
        int $carId,
        int $mappingId,
        string $campaignId,
        ?string $adsetId,
        int $impressions,
        int $clicks,
        float $spend
    ): void {
        DB::table('campaign_car_metrics_daily')->insert([
            'company_id' => $companyId,
            'car_id' => $carId,
            'mapping_id' => $mappingId,
            'campaign_id' => $campaignId,
            'adset_id' => $adsetId,
            'date' => now()->toDateString(),
            'impressions' => $impressions,
            'clicks' => $clicks,
            'spend_normalized' => $spend,
            'ctr' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : null,
            'cpc' => $clicks > 0 ? round($spend / $clicks, 4) : null,
            'cpm' => $impressions > 0 ? round(($spend / $impressions) * 1000, 2) : null,
            'allocation_factor' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createAttributionRow(
        int $companyId,
        int $carId,
        ?string $campaignId,
        ?string $adsetId,
        ?string $adId,
        int $strongIntentUsers,
        int $leads,
        int $whatsappClicks
    ): void {
        for ($i = 0; $i < max($strongIntentUsers, $leads, $whatsappClicks, 1); $i++) {
            DB::table('car_ad_attributions')->insert([
                'company_id' => $companyId,
                'car_id' => $carId,
                'dealer_id' => null,
                'source' => 'direct',
                'platform' => 'meta',
                'campaign_id' => $campaignId,
                'adset_id' => $adsetId,
                'ad_id' => $adId,
                'visitor_id' => (string) Str::uuid(),
                'session_id' => (string) Str::uuid(),
                'utm_source' => 'meta',
                'utm_medium' => 'cpc',
                'utm_campaign' => $campaignId,
                'utm_content' => null,
                'utm_id' => $adId ?? $adsetId ?? $campaignId,
                'click_id' => null,
                'first_interaction_at' => now(),
                'last_interaction_at' => now(),
                'has_whatsapp_click' => $i < $whatsappClicks,
                'has_lead' => $i < $leads,
                'has_strong_intent' => $i < $strongIntentUsers,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function createCompanyAndCar(): array
    {
        $planId = DB::table('plans')->insertGetId([
            'name' => 'Test Plan',
            'description' => 'Plan',
            'price' => 99,
            'car_limit' => 10,
            'features' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $companyId = DB::table('companies')->insertGetId([
            'nipc' => '123456789',
            'fiscal_name' => 'Xplendor Test Company',
            'trade_name' => 'Xplendor Test Company',
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
            'slug' => 'bmw-'.Str::lower(Str::random(6)),
            'vehicle_type' => 'car',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $modelId = DB::table('car_models')->insertGetId([
            'name' => 'Serie 3 '.Str::upper(Str::random(4)),
            'type' => 'other',
            'vehicle_type' => 'car',
            'car_brand_id' => $brandId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $categoryId = DB::table('car_categories')->insertGetId([
            'name' => 'Sedan '.Str::upper(Str::random(4)),
            'slug' => 'sedan-'.Str::lower(Str::random(6)),
            'vehicle_type' => 'car',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $car = Car::factory()->create([
            'company_id' => $companyId,
            'car_brand_id' => $brandId,
            'car_model_id' => $modelId,
            'car_category_id' => $categoryId,
            'vehicle_type' => 'car',
        ]);

        return [Company::query()->findOrFail($companyId), $car];
    }
}
