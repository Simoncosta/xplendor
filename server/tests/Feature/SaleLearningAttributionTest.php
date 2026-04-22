<?php

namespace Tests\Feature;

use App\Models\Car;
use App\Services\CarSaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class SaleLearningAttributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_mark_as_sold_creates_learning_snapshot_and_sale_attribution_once(): void
    {
        Mail::fake();

        [$companyId, $car] = $this->createCompanyAndCar();

        DB::table('car_ad_campaigns')->insert([
            'company_id' => $companyId,
            'car_id' => $car->id,
            'platform' => 'meta',
            'campaign_id' => 'cmp_sale',
            'campaign_name' => 'Sale Campaign',
            'adset_id' => 'adset_sale',
            'adset_name' => 'Sale Adset',
            'ad_id' => 'ad_sale',
            'ad_name' => 'Sale Ad',
            'level' => 'ad',
            'spend_split_pct' => 100,
            'is_active' => true,
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(1),
        ]);

        DB::table('car_ad_attributions')->insert([
            'company_id' => $companyId,
            'car_id' => $car->id,
            'source' => 'direct',
            'platform' => 'meta',
            'campaign_id' => 'cmp_sale',
            'adset_id' => 'adset_sale',
            'ad_id' => 'ad_sale',
            'visitor_id' => (string) Str::uuid(),
            'session_id' => (string) Str::uuid(),
            'first_interaction_at' => now()->subDays(2),
            'last_interaction_at' => now()->subDay(),
            'has_whatsapp_click' => true,
            'has_strong_intent' => true,
            'has_lead' => false,
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDay(),
        ]);

        DB::table('car_views')->insert([
            'company_id' => $companyId,
            'car_id' => $car->id,
            'ip_address' => '127.0.0.1',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        DB::table('car_interactions')->insert([
            'company_id' => $companyId,
            'car_id' => $car->id,
            'interaction_type' => 'whatsapp_click',
            'visitor_id' => (string) Str::uuid(),
            'session_id' => (string) Str::uuid(),
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        DB::table('car_interactions')->insert([
            'company_id' => $companyId,
            'car_id' => $car->id,
            'interaction_type' => 'call_click',
            'visitor_id' => (string) Str::uuid(),
            'session_id' => (string) Str::uuid(),
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        app(CarSaleService::class)->markAsSold($car, [
            'sold_at' => now(),
            'sale_price' => 14500,
            'buyer_age' => 34,
            'buyer_gender' => 'female',
            'skip_notification' => true,
        ]);

        app(CarSaleService::class)->markAsSold($car->refresh(), [
            'sold_at' => $car->sold_at,
            'sale_price' => 14500,
            'buyer_age' => 34,
            'buyer_gender' => 'female',
            'skip_notification' => true,
        ]);

        $this->assertDatabaseHas('cars', [
            'id' => $car->id,
            'status' => 'sold',
        ]);

        $this->assertSame(1, DB::table('car_sales_learning')->count());
        $this->assertDatabaseHas('car_sales_learning', [
            'company_id' => $companyId,
            'car_id' => $car->id,
            'sale_price' => 14500,
            'buyer_age' => 34,
            'buyer_gender' => 'female',
            'whatsapp_clicks_last_7d' => 1,
            'primary_contact_channel' => 'whatsapp',
        ]);

        $snapshot = DB::table('car_sales_learning')->first();
        $this->assertGreaterThan(0, $snapshot->contact_signal_score);
        $this->assertGreaterThan(0, $snapshot->peak_contact_signal_last_7d);
        $this->assertNotNull($snapshot->peak_contact_signal_at);
        $this->assertContains($snapshot->contact_signal_trend, ['up', 'stable', 'down']);
        $this->assertGreaterThan(0, $snapshot->sale_quality_score);

        $this->assertSame(1, DB::table('car_sale_attributions')->count());
        $this->assertDatabaseHas('car_sale_attributions', [
            'company_id' => $companyId,
            'car_id' => $car->id,
            'attributed_campaign_id' => 'cmp_sale',
            'attributed_adset_id' => 'adset_sale',
            'attributed_ad_id' => 'ad_sale',
            'attribution_model' => 'last_touch_recent_window',
            'match_type' => 'direct_ad',
        ]);

        $saleAttribution = DB::table('car_sale_attributions')->first();
        $this->assertNotNull($saleAttribution->time_to_sale_hours);
        $this->assertNotNull($saleAttribution->time_from_last_interaction_hours);
        $this->assertGreaterThan(0, $saleAttribution->confidence_score);
    }

    public function test_sale_attribution_falls_back_to_active_mapping_when_no_recent_interaction_exists(): void
    {
        Mail::fake();

        [$companyId, $car] = $this->createCompanyAndCar();

        DB::table('car_ad_campaigns')->insert([
            'company_id' => $companyId,
            'car_id' => $car->id,
            'platform' => 'meta',
            'campaign_id' => 'cmp_fallback_sale',
            'campaign_name' => 'Fallback Sale Campaign',
            'adset_id' => 'adset_fallback_sale',
            'adset_name' => 'Fallback Sale Adset',
            'ad_id' => null,
            'ad_name' => null,
            'level' => 'adset',
            'spend_split_pct' => 100,
            'is_active' => true,
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subHours(10),
        ]);

        app(CarSaleService::class)->markAsSold($car, [
            'sold_at' => now(),
            'sale_price' => 13200,
            'buyer_age' => 45,
            'buyer_gender' => 'male',
            'skip_notification' => true,
        ]);

        $this->assertDatabaseHas('car_sale_attributions', [
            'company_id' => $companyId,
            'car_id' => $car->id,
            'attributed_campaign_id' => 'cmp_fallback_sale',
            'attributed_adset_id' => 'adset_fallback_sale',
            'match_type' => 'fallback',
        ]);
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
            'nipc' => '123456780',
            'fiscal_name' => 'Learning Test Company',
            'trade_name' => 'Learning Test Company',
            'public_api_token' => (string) Str::uuid(),
            'plan_id' => $planId,
            'lead_distribution' => 'manual',
            'registration_fees' => 0,
            'export_promotion_price' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $brandId = DB::table('car_brands')->insertGetId([
            'name' => 'Fiat',
            'slug' => 'fiat',
            'vehicle_type' => 'car',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $modelId = DB::table('car_models')->insertGetId([
            'name' => '500',
            'type' => 'other',
            'vehicle_type' => 'car',
            'car_brand_id' => $brandId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $categoryId = DB::table('car_categories')->insertGetId([
            'name' => 'City',
            'slug' => 'city',
            'vehicle_type' => 'car',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $car = Car::factory()->create([
            'company_id' => $companyId,
            'car_brand_id' => $brandId,
            'car_model_id' => $modelId,
            'car_category_id' => $categoryId,
            'price_gross' => 15900,
            'created_at' => now()->subDays(42),
        ]);

        return [$companyId, $car];
    }
}
