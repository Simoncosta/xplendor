<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Car;
use App\Models\CarAdAttribution;
use App\Models\CarBrand;
use App\Models\CarModel;
use App\Models\CarSaleAttribution;
use App\Models\Company;
use App\Models\MetaAd;
use App\Models\MetaAdInsightDaily;
use App\Services\AttributionService;
use App\Services\CampaignToSaleAttributionService;
use App\Services\MetaAdCarAllocator;
use App\Services\SalesLearningService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * XPLENDOR — FASE 2E (B): atribuição de vendas a anúncios com tag.
 *
 * Ordem: evidência de clique (com cross_car marcado e menos confiança) → recurso
 * pela tag → recurso ao mapeamento manual só para campanhas SEM tag. Cobre a
 * visita (campanha a partir do catálogo), a precedência sem dupla contagem, a
 * aprendizagem, empresas sem tags (igual a antes), o comando de reatribuição
 * (simulação primeiro) e tenancy.
 */
class TagSaleAttributionTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-10-20 10:00:00';

    private Company $company;
    private Company $other;
    private int $brandId;
    private int $modelId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::NOW));
        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::NOW));

        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500011001', 'fiscal_name' => 'Stand A', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->other = Company::create(['nipc' => '500011002', 'fiscal_name' => 'Stand B', 'plan_id' => $planId, 'subscription_status' => 'active']);

        $brand = CarBrand::firstOrCreate(['slug' => 'vw'], ['name' => 'VW', 'vehicle_type' => 'car']);
        $this->brandId = $brand->id;
        $this->modelId = CarModel::firstOrCreate(['name' => 'Golf', 'car_brand_id' => $brand->id])->id;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function car(array $attrs = [], ?Company $company = null): Car
    {
        $car = Car::factory()->create(array_merge([
            'company_id' => ($company ?? $this->company)->id, 'car_brand_id' => $this->brandId, 'car_model_id' => $this->modelId,
            'vehicle_type' => 'car', 'status' => 'active', 'is_resume' => 0, 'price_gross' => 20000,
        ], array_diff_key($attrs, ['sold_at' => 1])));
        if (array_key_exists('sold_at', $attrs)) {
            DB::table('cars')->where('id', $car->id)->update(['sold_at' => $attrs['sold_at']]);
        }

        return $car->fresh();
    }

    private function adSpend(string $adId, string $name, float $spend, string $date, string $campaign = 'C1', ?Company $company = null): void
    {
        $companyId = ($company ?? $this->company)->id;
        MetaAdInsightDaily::create(['company_id' => $companyId, 'account_id' => '123', 'date' => $date, 'campaign_id' => $campaign,
            'adset_id' => "S-{$campaign}", 'ad_id' => $adId, 'ad_name' => $name, 'spend' => $spend, 'impressions' => 100, 'clicks' => 3]);
        MetaAd::updateOrCreate(['company_id' => $companyId, 'account_id' => '123', 'ad_id' => $adId],
            ['ad_name' => $name, 'campaign_id' => $campaign, 'adset_id' => "S-{$campaign}", 'effective_status' => 'ACTIVE']);
        app(MetaAdCarAllocator::class)->rebuild($companyId, '123');
    }

    private function mapping(Car $car, string $campaign, bool $active = true): int
    {
        return DB::table('car_ad_campaigns')->insertGetId([
            'company_id' => $car->company_id, 'car_id' => $car->id, 'platform' => 'meta', 'campaign_id' => $campaign,
            'level' => 'campaign', 'spend_split_pct' => 100, 'is_active' => $active, 'created_at' => now()->subDays(3), 'updated_at' => now()->subDays(3),
        ]);
    }

    private function click(Car $car, ?string $adId, ?string $campaign = null, string $source = 'direct', int $hoursAgo = 30): void
    {
        DB::table('car_ad_attributions')->insert([
            'company_id' => $car->company_id, 'car_id' => $car->id, 'source' => $source, 'platform' => 'meta',
            'campaign_id' => $campaign, 'adset_id' => null, 'ad_id' => $adId,
            'visitor_id' => (string) Str::uuid(), 'session_id' => (string) Str::uuid(),
            'has_whatsapp_click' => true, 'has_lead' => false, 'has_strong_intent' => false,
            'first_interaction_at' => now()->subHours($hoursAgo), 'last_interaction_at' => now()->subHours($hoursAgo),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function attribute(Car $car): array
    {
        return app(CampaignToSaleAttributionService::class)->attributeSale($car, ['sold_at' => now()]);
    }

    // ── visita: campanha a partir do catálogo ────────────────────────────────

    public function test_visit_with_tagged_ad_id_gets_campaign_and_adset_from_catalog(): void
    {
        $car = $this->car();
        $this->adSpend('120001', "Golf [id:{$car->id}]", 10.0, '2026-10-18', 'C1');

        $request = Request::create('/api/public/track', 'POST', ['tracking' => [
            'visitor_id' => (string) Str::uuid(), 'session_id' => (string) Str::uuid(),
            'utm_source' => 'meta', 'utm_medium' => 'paid', 'ad_id' => '120001',
        ]]);
        $a = app(AttributionService::class)->trackVisit($car, $request);

        $this->assertSame('120001', $a->ad_id);
        $this->assertSame('C1', $a->campaign_id);
        $this->assertSame('S-C1', $a->adset_id);
        $this->assertSame('direct', $a->source);
    }

    // ── venda: evidência de clique e cross_car ───────────────────────────────

    public function test_click_on_the_cars_own_tagged_ad_is_a_direct_ad_match(): void
    {
        $car = $this->car(['status' => 'sold', 'sold_at' => now()]);
        $this->adSpend('120001', "Golf [id:{$car->id}]", 10.0, '2026-10-18');
        $this->click($car, '120001', 'C1');

        $r = $this->attribute($car);

        $this->assertSame('direct_ad', $r['match_type']);
        $this->assertSame('120001', $r['ad_id']);
        $this->assertFalse($r['source_snapshot']['cross_car']);
    }

    public function test_click_on_another_cars_ad_counts_as_cross_car_with_less_confidence(): void
    {
        $advertised = $this->car();
        $bought = $this->car(['status' => 'sold', 'sold_at' => now()]);
        $this->adSpend('120001', "Golf [id:{$advertised->id}]", 10.0, '2026-10-18');
        $this->click($bought, '120001', 'C1');

        $own = $this->car(['status' => 'sold', 'sold_at' => now()]);
        $this->adSpend('120002', "Polo [id:{$own->id}]", 10.0, '2026-10-18');
        $this->click($own, '120002', 'C1');

        $cross = $this->attribute($bought);
        $direct = $this->attribute($own);

        $this->assertSame('cross_car_ad', $cross['match_type']);
        $this->assertSame('120001', $cross['ad_id']);
        $this->assertTrue($cross['source_snapshot']['cross_car']);
        $this->assertSame([$advertised->id], $cross['source_snapshot']['ad_tag_car_ids']);
        $this->assertSame($direct['confidence_score'] - 15, $cross['confidence_score']);
        $this->assertStringContainsString("anúncio da viatura n.º {$advertised->id}", $cross['confidence_reason']);
    }

    // ── recurso pela tag e precedência sem dupla contagem ────────────────────

    public function test_tag_fallback_when_there_is_no_click(): void
    {
        $recent = $this->car(['status' => 'sold', 'sold_at' => now()]);
        $this->adSpend('120001', "Golf [id:{$recent->id}]", 10.0, '2026-10-18');   // 2 dias antes
        $older = $this->car(['status' => 'sold', 'sold_at' => now()]);
        $this->adSpend('120002', "Polo [id:{$older->id}]", 10.0, '2026-10-10');    // 10 dias antes
        $tooOld = $this->car(['status' => 'sold', 'sold_at' => now()]);
        $this->adSpend('120003', "Up [id:{$tooOld->id}]", 10.0, '2026-09-30');     // 20 dias antes

        $r = $this->attribute($recent);
        $this->assertSame('tag_fallback', $r['match_type']);
        $this->assertSame('120001', $r['ad_id']);
        $this->assertSame('C1', $r['campaign_id']);
        $this->assertSame(40, $r['confidence_score']);
        $this->assertSame('meta_ad_tag', $r['source_snapshot']['source']);

        $this->assertSame(30, $this->attribute($older)['confidence_score']);
        $this->assertSame(0, $this->attribute($tooOld)['confidence_score']);   // fora da janela de 14 dias
    }

    public function test_manual_mapping_of_a_tagged_campaign_never_counts_tag_wins(): void
    {
        $car = $this->car(['status' => 'sold', 'sold_at' => now()]);
        $this->mapping($car, 'C1');                                           // campanha que tem tag
        $this->adSpend('120001', "Golf [id:{$car->id}]", 10.0, '2026-10-18', 'C1');
        $this->click($car, null, 'C1', 'fallback');                           // visita creditada pelo recurso ao mapeamento

        $r = $this->attribute($car);

        $this->assertSame('tag_fallback', $r['match_type']);                 // nem o registo de recurso nem o mapeamento
        $this->assertSame('meta_ad_tag', $r['source_snapshot']['source']);
    }

    public function test_manual_mapping_still_counts_for_campaigns_without_tag(): void
    {
        $car = $this->car(['status' => 'sold', 'sold_at' => now()]);
        $other = $this->car();
        $this->adSpend('120001', "Golf [id:{$other->id}]", 10.0, '2026-10-18', 'C1');   // a empresa usa tags (noutra campanha)
        $this->mapping($car, 'C9');                                                       // campanha antiga, sem tag

        $r = $this->attribute($car);

        $this->assertSame('fallback', $r['match_type']);
        $this->assertSame('C9', $r['campaign_id']);
        $this->assertSame('car_ad_campaigns', $r['source_snapshot']['source']);
    }

    public function test_company_without_tags_keeps_the_old_behaviour(): void
    {
        $car = $this->car(['status' => 'sold', 'sold_at' => now()]);
        $this->mapping($car, 'C1');
        $this->click($car, null, 'C1', 'fallback');

        $r = $this->attribute($car);

        $this->assertSame('campaign_match', $r['match_type']);   // o registo de recurso conta, como antes
        $this->assertSame('C1', $r['campaign_id']);
    }

    // ── aprendizagem ──────────────────────────────────────────────────────────

    public function test_sales_learning_context_reads_tagged_ads_and_untagged_mappings_only(): void
    {
        $car = $this->car(['status' => 'sold', 'sold_at' => now()]);
        $this->adSpend('120001', "Golf [id:{$car->id}]", 10.0, '2026-10-15', 'C1');
        $this->mapping($car, 'C1');   // campanha com tag: não conta
        $this->mapping($car, 'C9');   // campanha antiga sem tag: conta

        $m = new \ReflectionMethod(SalesLearningService::class, 'campaignContext');
        $m->setAccessible(true);
        $ctx = $m->invoke(app(SalesLearningService::class), $car, \Illuminate\Support\Carbon::now());

        $this->assertEqualsCanonicalizing(['C9', 'C1'], $ctx['campaign_ids']);
        $this->assertSame(['120001'], $ctx['ad_ids']);
        $this->assertSame(1, DB::table('car_ad_campaigns')->where('car_id', $car->id)->where('campaign_id', 'C1')->count());
    }

    // ── reatribuição: simulação primeiro ─────────────────────────────────────

    public function test_reattribute_command_simulates_first_and_only_writes_with_flag(): void
    {
        Storage::fake('local');
        $car = $this->car(['status' => 'sold', 'sold_at' => '2026-10-19 12:00:00']);
        $this->mapping($car, 'C1');
        CarSaleAttribution::create(['company_id' => $car->company_id, 'car_id' => $car->id, 'sold_at' => '2026-10-19 12:00:00',
            'attributed_platform' => 'meta', 'attributed_campaign_id' => 'C1', 'match_type' => 'fallback', 'confidence_score' => 35]);
        $this->adSpend('120001', "Golf [id:{$car->id}]", 10.0, '2026-10-18', 'C1');   // agora a campanha tem tag

        $this->artisan('meta:reattribute-sales', ['--since' => '2026-10-01'])
            ->expectsOutputToContain('SIMULAÇÃO (nada foi gravado): 1 vendas · 0 sem alteração · 1 mudariam · 0 novas')
            ->assertSuccessful();
        $this->assertSame('fallback', CarSaleAttribution::sole()->match_type);
        $files = Storage::disk('local')->files('reports');
        $this->assertCount(1, $files);
        $this->assertStringContainsString('tag_fallback', Storage::disk('local')->get($files[0]));

        $this->artisan('meta:reattribute-sales', ['--since' => '2026-10-01', '--write' => true])->assertSuccessful();
        $this->assertSame('tag_fallback', CarSaleAttribution::sole()->match_type);
        $this->assertSame('120001', CarSaleAttribution::sole()->attributed_ad_id);
    }

    // ── tenancy ───────────────────────────────────────────────────────────────

    public function test_ads_of_another_company_never_mark_cross_car_or_feed_the_fallback(): void
    {
        $car = $this->car(['status' => 'sold', 'sold_at' => now()]);
        $foreign = $this->car([], $this->other);
        $this->adSpend('120009', "Golf [id:{$foreign->id}]", 50.0, '2026-10-18', 'C1', $this->other);
        $this->click($car, '120009', null);   // o mesmo ad_id, mas o anúncio é de outra empresa

        $r = $this->attribute($car);

        $this->assertSame('direct_ad', $r['match_type']);   // sem cross_car: o catálogo é da empresa B
        $this->assertFalse($r['source_snapshot']['cross_car']);

        $noClick = $this->car(['status' => 'sold', 'sold_at' => now()]);
        $this->assertSame(0, $this->attribute($noClick)['confidence_score']);   // sem recurso pela tag de B
    }
}
