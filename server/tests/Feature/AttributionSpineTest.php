<?php

namespace Tests\Feature;

use App\Models\Car;
use App\Models\Company;
use App\Services\AttributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttributionSpineTest extends TestCase
{
    use RefreshDatabase;

    public function test_track_endpoint_creates_and_updates_attribution_spine(): void
    {
        Queue::fake();
        Log::spy();

        [$company, $car] = $this->createCompanyAndCar();

        DB::table('car_ad_campaigns')->insert([
            'company_id' => $company->id,
            'car_id' => $car->id,
            'platform' => 'meta',
            'campaign_id' => 'cmp_123',
            'campaign_name' => 'Campaign 123',
            'adset_id' => 'adset_456',
            'adset_name' => 'Adset 456',
            'ad_id' => 'ad_789',
            'ad_name' => 'Ad 789',
            'level' => 'ad',
            'spend_split_pct' => 100,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $visitorId = (string) Str::uuid();
        $sessionId = (string) Str::uuid();

        $tracking = [
            'visitor_id' => $visitorId,
            'session_id' => $sessionId,
            'referrer' => 'https://facebook.com',
            'landing_path' => '/cars/'.$car->id.'?utm_source=meta&utm_medium=paid_social&utm_campaign=Campaign%20123&utm_id=ad_789&fbclid=fbclid_001',
            'utm_source' => 'meta',
            'utm_medium' => 'paid_social',
            'utm_campaign' => 'Campaign 123',
            'utm_id' => 'ad_789',
        ];

        $this->postJson('/api/public/track?token='.$company->public_api_token, [
            'type' => 'car_view',
            'data' => [
                'car_id' => $car->id,
                'client_view_key' => (string) Str::uuid(),
            ],
            'tracking' => $tracking,
        ])->assertCreated();

        $this->assertDatabaseHas('car_ad_attributions', [
            'company_id' => $company->id,
            'car_id' => $car->id,
            'visitor_id' => $visitorId,
            'session_id' => $sessionId,
            'campaign_id' => 'cmp_123',
            'adset_id' => 'adset_456',
            'ad_id' => 'ad_789',
            'utm_id' => 'ad_789',
            'click_id' => 'fbclid_001',
        ]);

        $this->postJson('/api/public/track?token='.$company->public_api_token, [
            'type' => 'whatsapp_click',
            'data' => [
                'car_id' => $car->id,
                'page_url' => '/cars/'.$car->id.'?utm_source=meta&utm_campaign=Campaign%20123&utm_id=ad_789',
            ],
            'tracking' => $tracking,
        ])->assertCreated();

        app(AttributionService::class)->markStrongIntent($visitorId, $car->id);

        $this->postJson('/api/public/track?token='.$company->public_api_token, [
            'type' => 'car_lead',
            'data' => [
                'car_id' => $car->id,
                'name' => 'Jane Doe',
                'email' => 'jane@gmail.com',
            ],
            'tracking' => $tracking,
        ])->assertCreated();

        $this->assertDatabaseHas('car_ad_attributions', [
            'company_id' => $company->id,
            'car_id' => $car->id,
            'visitor_id' => $visitorId,
            'has_whatsapp_click' => true,
            'has_strong_intent' => true,
            'has_lead' => true,
        ]);

        $summary = app(AttributionService::class)->getAttributionSummary($car->id);

        $this->assertSame([
            'total_visitors' => 1,
            'total_sessions' => 1,
            'whatsapp_clicks' => 1,
            'strong_intent_users' => 1,
            'leads' => 1,
        ], $summary['totals']);

        $this->assertCount(1, $summary['rows']);
        $this->assertSame('ad_789', $summary['rows'][0]['ad_id']);
        $this->assertSame('adset_456', $summary['rows'][0]['adset_id']);
        $this->assertSame('cmp_123', $summary['rows'][0]['campaign_id']);

        Log::assertLogged('info', fn ($message, array $context) => $message === '[Attribution] CREATED'
            && ($context['visitor_id'] ?? null) === $visitorId
            && ($context['session_id'] ?? null) === $sessionId);
        Log::assertLogged('info', fn ($message, array $context) => $message === '[Attribution] RESOLVED_UTM');
    }

    public function test_same_visitor_different_sessions_create_two_attributions_and_fallback_is_used(): void
    {
        Mail::fake();
        Queue::fake();
        Log::spy();

        [$company, $car] = $this->createCompanyAndCar();

        DB::table('car_ad_campaigns')->insert([
            'company_id' => $company->id,
            'car_id' => $car->id,
            'platform' => 'meta',
            'campaign_id' => 'cmp_fallback',
            'campaign_name' => 'Fallback Campaign',
            'adset_id' => 'adset_fallback',
            'adset_name' => 'Fallback Adset',
            'ad_id' => null,
            'ad_name' => null,
            'level' => 'adset',
            'spend_split_pct' => 100,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $visitorId = (string) Str::uuid();
        $firstSessionId = (string) Str::uuid();
        $secondSessionId = (string) Str::uuid();

        $payload = [
            'car_id' => $car->id,
            'visitor_id' => $visitorId,
            'landing_path' => '/cars/'.$car->id,
        ];

        $this->postJson('/api/public/car-view?token='.$company->public_api_token, [
            ...$payload,
            'session_id' => $firstSessionId,
        ])->assertOk();

        $this->postJson('/api/public/car-view?token='.$company->public_api_token, [
            ...$payload,
            'session_id' => $secondSessionId,
        ])->assertOk();

        $this->assertSame(2, DB::table('car_ad_attributions')->count());
        $this->assertDatabaseHas('car_ad_attributions', [
            'company_id' => $company->id,
            'car_id' => $car->id,
            'visitor_id' => $visitorId,
            'session_id' => $firstSessionId,
            'source' => 'fallback',
            'campaign_id' => 'cmp_fallback',
            'adset_id' => 'adset_fallback',
        ]);
        $this->assertDatabaseHas('car_ad_attributions', [
            'company_id' => $company->id,
            'car_id' => $car->id,
            'visitor_id' => $visitorId,
            'session_id' => $secondSessionId,
            'source' => 'fallback',
            'campaign_id' => 'cmp_fallback',
            'adset_id' => 'adset_fallback',
        ]);

        Log::assertLogged('info', fn ($message, array $context) => $message === '[Attribution] FALLBACK_USED'
            && ($context['visitor_id'] ?? null) === $visitorId);
    }

    public function test_same_visitor_same_session_reuses_attribution_and_keeps_first_touch_locked(): void
    {
        Mail::fake();
        Queue::fake();

        [$company, $car] = $this->createCompanyAndCar();

        DB::table('car_ad_campaigns')->insert([
            'company_id' => $company->id,
            'car_id' => $car->id,
            'platform' => 'meta',
            'campaign_id' => 'cmp_first',
            'campaign_name' => 'Campaign First',
            'adset_id' => 'adset_first',
            'adset_name' => 'Adset First',
            'ad_id' => 'ad_first',
            'ad_name' => 'Ad First',
            'level' => 'ad',
            'spend_split_pct' => 100,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('car_ad_campaigns')->insert([
            'company_id' => $company->id,
            'car_id' => $car->id,
            'platform' => 'meta',
            'campaign_id' => 'cmp_second',
            'campaign_name' => 'Campaign Second',
            'adset_id' => 'adset_second',
            'adset_name' => 'Adset Second',
            'ad_id' => 'ad_second',
            'ad_name' => 'Ad Second',
            'level' => 'ad',
            'spend_split_pct' => 100,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $visitorId = (string) Str::uuid();
        $sessionId = (string) Str::uuid();

        $this->postJson('/api/public/car-view?token='.$company->public_api_token, [
            'car_id' => $car->id,
            'visitor_id' => $visitorId,
            'session_id' => $sessionId,
            'landing_path' => '/cars/'.$car->id.'?utm_source=meta&utm_medium=cpc&utm_campaign=Campaign%20First&utm_id=ad_first&ad_id=ad_first',
            'utm_source' => 'meta',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'Campaign First',
            'utm_id' => 'ad_first',
            'ad_id' => 'ad_first',
            'click_id' => 'fbclid_first',
        ])->assertOk();

        $this->postJson('/api/public/car-lead?token='.$company->public_api_token, [
            'car_id' => $car->id,
            'name' => 'John Doe',
            'email' => 'john@gmail.com',
            'phone' => '912345678',
            'visitor_id' => $visitorId,
            'session_id' => $sessionId,
            'landing_path' => '/cars/'.$car->id.'?utm_source=meta&utm_medium=cpc&utm_campaign=Campaign%20Second&utm_id=ad_second&ad_id=ad_second',
            'utm_source' => 'meta',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'Campaign Second',
            'utm_id' => 'ad_second',
            'ad_id' => 'ad_second',
            'click_id' => 'fbclid_second',
        ])->assertOk();

        $this->assertSame(1, DB::table('car_ad_attributions')->count());
        $this->assertDatabaseHas('car_ad_attributions', [
            'company_id' => $company->id,
            'car_id' => $car->id,
            'visitor_id' => $visitorId,
            'session_id' => $sessionId,
            'source' => 'direct',
            'campaign_id' => 'cmp_first',
            'adset_id' => 'adset_first',
            'ad_id' => 'ad_first',
            'utm_campaign' => 'Campaign First',
            'utm_id' => 'ad_first',
            'click_id' => 'fbclid_first',
            'has_lead' => true,
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
            'slug' => 'bmw',
            'vehicle_type' => 'car',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $modelId = DB::table('car_models')->insertGetId([
            'name' => 'Serie 3',
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
