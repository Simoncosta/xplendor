<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Car;
use App\Models\CarBrand;
use App\Models\CarMarketAggregate;
use App\Models\CarModel;
use App\Models\Company;
use App\Models\MetaAd;
use App\Models\MetaAdInsightDaily;
use App\Models\User;
use App\Recommendations\RecommendationEngine;
use App\Recommendations\Rules\Automotive\HighViewsNoContactsRule;
use App\Services\Automotive\AutomotiveHubService;
use App\Services\MetaAdCarAllocator;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XPLENDOR — FASE 2D: regras novas do Automóvel no motor de recomendações.
 *
 *   1. Anúncio de viatura vendida ainda ativo (prioridade máxima);
 *   2. Acima do mercado e parada (fundida na regra de preço migrada);
 *   3. Muitas vistas, zero contactos (p75 da empresa e ≥ 30, 14 dias);
 *   4. Gasto sem lead (gasto com tag ≥ 30 € em 14 dias, 0 leads pagas).
 * Cada regra: dispara e não dispara, limiares por tipo, exata vs aproximada,
 * p75 relativo, sem duplicados, configuração por empresa e tenancy.
 */
class AutomotiveRulesTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-10-03 10:00:00';

    private Company $company;
    private Company $other;
    private User $otherUser;
    private int $brandId;
    private int $modelId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::NOW));
        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::NOW));

        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500009001', 'fiscal_name' => 'Stand A', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->other = Company::create(['nipc' => '500009002', 'fiscal_name' => 'Stand B', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->otherUser = User::factory()->create(['company_id' => $this->other->id, 'role' => 'admin']);

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

    private function car(int $daysOld, array $attrs = [], ?Company $company = null): Car
    {
        $car = Car::factory()->create(array_merge([
            'company_id' => ($company ?? $this->company)->id, 'car_brand_id' => $this->brandId, 'car_model_id' => $this->modelId,
            'vehicle_type' => 'car', 'status' => 'active', 'is_resume' => 0, 'price_gross' => 20000, 'promo_price_gross' => null,
            'description_website_pt' => str_repeat('Descrição completa. ', 12),
        ], array_diff_key($attrs, ['sold_at' => 1])));

        $update = ['created_at' => now()->subDays($daysOld), 'car_created_at' => null];
        if (array_key_exists('sold_at', $attrs)) {
            $update['sold_at'] = $attrs['sold_at'];
        }
        DB::table('cars')->where('id', $car->id)->update($update);

        return $car->fresh();
    }

    private function aggregate(Car $car, float $median, string $confidence = 'high', bool $fallback = false): void
    {
        CarMarketAggregate::create([
            'car_id' => $car->id, 'vehicle_type' => $car->vehicle_type, 'status' => 'success', 'confidence' => $confidence,
            'comparables_count' => 12, 'median_price' => $median, 'fallback_used' => $fallback,
        ]);
    }

    private function views(Car $car, int $n, int $daysAgo = 1): void
    {
        $rows = array_fill(0, $n, ['company_id' => $car->company_id, 'car_id' => $car->id, 'ip_address' => '127.0.0.1',
            'created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo)]);
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('car_views')->insert($chunk);
        }
    }

    private function interaction(Car $car, string $type, int $daysAgo = 1, int $n = 1): void
    {
        for ($i = 0; $i < $n; $i++) {
            DB::table('car_interactions')->insert(['company_id' => $car->company_id, 'car_id' => $car->id, 'interaction_type' => $type,
                'visitor_id' => "v{$car->id}{$i}", 'session_id' => "s{$car->id}{$i}", 'created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo)]);
        }
    }

    private function lead(Car $car, int $daysAgo = 1, string $channel = 'paid'): void
    {
        DB::table('car_leads')->insert(['name' => 'Cliente', 'email' => 'c@x.pt', 'car_id' => $car->id, 'company_id' => $car->company_id,
            'status' => 'new', 'channel' => $channel, 'created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo)]);
    }

    /** Gasto de um anúncio num dia (ingestão por anúncio simulada) + estado + atribuição. */
    private function adSpend(string $adId, string $name, float $spend, string $date, string $status = 'ACTIVE', ?Company $company = null): void
    {
        $companyId = ($company ?? $this->company)->id;
        MetaAdInsightDaily::create(['company_id' => $companyId, 'account_id' => '123', 'date' => $date, 'campaign_id' => 'C1',
            'adset_id' => 'S1', 'ad_id' => $adId, 'ad_name' => $name, 'spend' => $spend, 'impressions' => 100, 'clicks' => 3]);
        MetaAd::updateOrCreate(['company_id' => $companyId, 'account_id' => '123', 'ad_id' => $adId],
            ['ad_name' => $name, 'campaign_id' => 'C1', 'effective_status' => $status]);
        app(MetaAdCarAllocator::class)->rebuild($companyId, '123');
    }

    private function recs(?string $ruleKey = null, ?Company $company = null): array
    {
        $all = app(RecommendationEngine::class)->forCompany($company ?? $this->company, 'automotive')['recommendations'];

        return array_values(array_filter($all, fn ($r) => $ruleKey === null || $r['rule_key'] === $ruleKey));
    }

    private function setting(string $ruleKey, array $params = [], bool $enabled = true): void
    {
        DB::table('company_recommendation_settings')->insert(['company_id' => $this->company->id, 'rule_key' => $ruleKey,
            'enabled' => $enabled, 'params' => json_encode($params), 'created_at' => now(), 'updated_at' => now()]);
    }

    // ── 1. Anúncio de viatura vendida ainda ativo ───────────────────────────

    public function test_sold_car_with_active_tagged_ad_fires_with_maximum_priority(): void
    {
        $car = $this->car(90, ['status' => 'sold', 'sold_at' => '2026-09-20 15:00:00']);
        $this->adSpend('A1', "AC-A [id:{$car->id}] Golf", 10.0, '2026-09-19');   // antes da venda
        $this->adSpend('A1', "AC-A [id:{$car->id}] Golf", 18.0, '2026-09-25');   // depois

        $r = $this->recs('automotive_sold_car_ad_active');

        $this->assertCount(1, $r);
        $this->assertSame(100, $r[0]['priority']);
        $this->assertSame('high', $r[0]['level']);
        $this->assertSame(
            "A viatura VW Golf {$car->version} (n.º {$car->id}) foi vendida a 20/09 e o anúncio «AC-A [id:{$car->id}] Golf» continua ativo: 18 € gastos desde a venda.",
            $r[0]['why']
        );
        $this->assertSame(18.0, $r[0]['evidence']['post_sale_spend']);
        $this->assertSame([['ad_id' => 'A1', 'ad_name' => "AC-A [id:{$car->id}] Golf"]], $r[0]['evidence']['active_ads']);
        $this->assertSame('Abrir no Gestor de Anúncios', $r[0]['action']['label']);
        $this->assertSame('https://adsmanager.facebook.com/adsmanager/manage/ads?act=123&selected_ad_ids=A1', $r[0]['action']['url']);
        $this->assertStringContainsString('só tem acesso de leitura', $r[0]['action']['suggestion']);
    }

    public function test_sold_car_with_post_sale_spend_fires_even_if_ad_is_paused(): void
    {
        $car = $this->car(90, ['status' => 'sold', 'sold_at' => '2026-09-20 15:00:00']);
        $this->adSpend('A1', "Golf [id:{$car->id}]", 7.5, '2026-09-22', 'PAUSED');

        $r = $this->recs('automotive_sold_car_ad_active');

        $this->assertCount(1, $r);
        $this->assertStringContainsString('registou 8 € de gasto depois da venda', $r[0]['why']);
        $this->assertSame([], $r[0]['evidence']['active_ads']);
        $this->assertSame(7.5, $r[0]['evidence']['recent_post_sale_spend']);
    }

    public function test_sold_car_rule_does_not_fire_without_active_ad_or_post_sale_spend(): void
    {
        $paused = $this->car(90, ['status' => 'sold', 'sold_at' => '2026-09-20 15:00:00']);
        $this->adSpend('A1', "Golf [id:{$paused->id}]", 20.0, '2026-09-20', 'PAUSED');   // dia da venda = antes

        $inStock = $this->car(10);
        $this->adSpend('A2', "Polo [id:{$inStock->id}]", 20.0, '2026-10-01', 'ACTIVE');  // não vendida

        $old = $this->car(200, ['status' => 'sold', 'sold_at' => '2026-05-01 10:00:00']);
        $this->adSpend('A3', "Up [id:{$old->id}]", 9.0, '2026-06-01', 'PAUSED');         // fora dos 30 dias

        $this->assertSame([], $this->recs('automotive_sold_car_ad_active'));
    }

    public function test_sold_car_lookback_is_configurable(): void
    {
        $old = $this->car(200, ['status' => 'sold', 'sold_at' => '2026-07-01 10:00:00']);
        $this->adSpend('A3', "Up [id:{$old->id}]", 9.0, '2026-08-01', 'PAUSED');
        $this->setting('automotive_sold_car_ad_active', ['lookback_days' => 90]);

        $this->assertCount(1, $this->recs('automotive_sold_car_ad_active'));
    }

    // ── 2. Acima do mercado e parada (fundida) ──────────────────────────────

    public function test_above_market_and_stale_uses_the_threshold_of_the_vehicle_type(): void
    {
        $car = $this->car(50, ['price_gross' => 23000]);                                 // carro: limiar 45
        $this->aggregate($car, 20000);
        $mhYoung = $this->car(100, ['vehicle_type' => 'motorhome', 'price_gross' => 23000]); // autocaravana: limiar 120
        $this->aggregate($mhYoung, 20000);
        $mhOld = $this->car(130, ['vehicle_type' => 'motorhome', 'price_gross' => 23000]);
        $this->aggregate($mhOld, 20000);

        $byCar = collect($this->recs('automotive_price_above_market'))->keyBy('evidence.car_id');

        $this->assertSame('price_above_market_stale', $byCar[$car->id]['evidence']['issue_type']);
        $this->assertSame('Acima do mercado e parada', $byCar[$car->id]['title']);
        $this->assertSame(45, $byCar[$car->id]['evidence']['threshold_days']);
        $this->assertSame('price_above_market', $byCar[$mhYoung->id]['evidence']['issue_type']);   // não parada
        $this->assertSame('price_above_market_stale', $byCar[$mhOld->id]['evidence']['issue_type']);
        $this->assertSame(120, $byCar[$mhOld->id]['evidence']['threshold_days']);
    }

    public function test_above_market_and_stale_never_on_approximate_or_low_confidence(): void
    {
        $approx = $this->car(130, ['vehicle_type' => 'motorhome', 'price_gross' => 26000]);
        $this->aggregate($approx, 20000, 'high', true);    // cascata antiga de autocaravana → aproximada
        $low = $this->car(130, ['vehicle_type' => 'motorhome', 'price_gross' => 26000]);
        $this->aggregate($low, 20000, 'low');              // confiança baixa

        $this->assertSame([], $this->recs('automotive_price_above_market'));

        // Continuam paradas, mas sem "acima do mercado": sem bónus nem "rever o preço".
        $dead = collect($this->recs('automotive_dead_stock'))->keyBy('evidence.car_id');
        $this->assertSame('Criar um novo destaque e atualizar o anúncio.', $dead[$approx->id]['action']['suggestion']);
        $this->assertSame($dead[$low->id]['priority'], $dead[$approx->id]['priority']);
    }

    public function test_above_market_and_stale_is_not_duplicated_by_the_dead_stock_rule(): void
    {
        $car = $this->car(100, ['price_gross' => 23000]);
        $this->aggregate($car, 20000);

        $forCar = collect($this->recs())->where('evidence.car_id', $car->id);
        $this->assertSame(['automotive_price_above_market'], $forCar->pluck('rule_key')->intersect(['automotive_price_above_market', 'automotive_dead_stock'])->values()->all());
        $this->assertSame(1, $forCar->where('evidence.issue_type', 'price_above_market_stale')->count());
    }

    // ── 3. Muitas vistas, zero contactos (14 dias) ──────────────────────────

    public function test_high_views_no_contacts_is_relative_to_the_company_p75(): void
    {
        $c10 = $this->car(20); $this->views($c10, 10);
        $c20 = $this->car(20); $this->views($c20, 20);
        $c40 = $this->car(20); $this->views($c40, 40);                                  // ≥ 30 mas < p75
        $c80 = $this->car(20); $this->views($c80, 80); $this->interaction($c80, 'call_click');   // tem contacto
        $c100 = $this->car(20); $this->views($c100, 100); $this->interaction($c100, 'form_open'); // form não é contacto

        // p75 de [10, 20, 40, 80, 100] = 80.
        $this->assertSame(80.0, HighViewsNoContactsRule::percentile([10, 20, 40, 80, 100], 0.75));

        $r = $this->recs('automotive_high_views_no_contacts');
        $this->assertSame([$c100->id], array_column(array_column($r, 'evidence'), 'car_id'));
        $this->assertSame(80.0, $r[0]['evidence']['p75_views']);
        $this->assertStringContainsString('Teve 100 vistas nos últimos 14 dias, acima do percentil 75 do stock (80 vistas)', $r[0]['why']);
    }

    public function test_high_views_requires_at_least_30_views_and_counts_only_14_days(): void
    {
        // Empresa com pouco tráfego: p75 baixo, mas ninguém chega às 30 vistas.
        foreach ([5, 10, 20, 25] as $n) {
            $c = $this->car(20);
            $this->views($c, $n);
        }
        $oldViews = $this->car(20);
        $this->views($oldViews, 200, 20);   // fora dos 14 dias

        $this->assertSame([], $this->recs('automotive_high_views_no_contacts'));
    }

    public function test_when_p75_is_below_the_minimum_the_why_cites_the_minimum(): void
    {
        foreach (range(1, 4) as $i) {
            $this->car(20);                       // stock sem vistas: p75 = 0
        }
        $c = $this->car(20);
        $this->views($c, 40);

        $r = $this->recs('automotive_high_views_no_contacts');
        $this->assertSame([$c->id], array_column(array_column($r, 'evidence'), 'car_id'));
        $this->assertStringContainsString('acima do mínimo de 30 vistas desta verificação (o percentil 75 do stock é de 0 vistas)', $r[0]['why']);
    }

    public function test_any_of_the_four_contact_types_blocks_the_rule(): void
    {
        foreach (['whatsapp_click', 'call_click', 'show_phone', 'copy_phone'] as $type) {
            $c = $this->car(20);
            $this->views($c, 100);
            $this->interaction($c, $type);
        }

        $this->assertSame([], $this->recs('automotive_high_views_no_contacts'));
    }

    // ── 4. Gasto sem lead ───────────────────────────────────────────────────

    public function test_spend_without_paid_lead_fires_above_threshold(): void
    {
        $car = $this->car(20);
        $this->adSpend('A1', "Golf [id:{$car->id}]", 20.0, '2026-09-30');
        $this->adSpend('A1', "Golf [id:{$car->id}]", 15.0, '2026-10-01');
        $this->lead($car, 2, 'direct');   // não é paga

        $r = $this->recs('automotive_spend_without_lead');

        $this->assertCount(1, $r);
        $this->assertSame(35.0, $r[0]['evidence']['tag_spend']);
        $this->assertStringContainsString('registaram 35,00 € de gasto nos últimos 14 dias e nenhuma lead com origem paga', $r[0]['why']);
        $this->assertStringContainsString('selected_ad_ids=A1', $r[0]['action']['url']);
    }

    public function test_spend_without_lead_does_not_fire_with_paid_lead_low_spend_or_old_spend(): void
    {
        $withLead = $this->car(20);
        $this->adSpend('A1', "Golf [id:{$withLead->id}]", 50.0, '2026-10-01');
        $this->lead($withLead, 1, 'paid');

        $low = $this->car(20);
        $this->adSpend('A2', "Polo [id:{$low->id}]", 25.0, '2026-10-01');

        $old = $this->car(60);
        $this->adSpend('A3', "Up [id:{$old->id}]", 80.0, '2026-09-10');   // fora dos 14 dias

        $this->assertSame([], $this->recs('automotive_spend_without_lead'));

        // X configurável por empresa: com 20 €, o de 25 € passa a disparar.
        $this->setting('automotive_spend_without_lead', ['min_spend' => 20]);
        $this->assertSame([$low->id], array_column(array_column($this->recs('automotive_spend_without_lead'), 'evidence'), 'car_id'));
    }

    public function test_sold_car_spend_is_not_duplicated_between_rules(): void
    {
        $car = $this->car(90, ['status' => 'sold', 'sold_at' => '2026-09-25 10:00:00']);
        $this->adSpend('A1', "Golf [id:{$car->id}]", 60.0, '2026-09-28');

        $this->assertSame([], $this->recs('automotive_spend_without_lead'));
        $this->assertCount(1, $this->recs('automotive_sold_car_ad_active'));
    }

    // ── Escala de prioridade e desempates (decisão 5) ───────────────────────

    public function test_widened_criteria_on_a_car_counts_as_exact(): void
    {
        $car = $this->car(100, ['price_gross' => 23000]);
        $this->aggregate($car, 20000, 'high', true);   // carro com recurso: mantém marca e modelo

        $r = $this->recs('automotive_price_above_market');
        $this->assertCount(1, $r);
        $this->assertSame('price_above_market_stale', $r[0]['evidence']['issue_type']);
    }

    public function test_priority_100_is_reserved_for_sold_car_with_active_ad_and_post_sale_spend(): void
    {
        $full = $this->car(90, ['status' => 'sold', 'sold_at' => '2026-09-20 15:00:00']);
        $this->adSpend('A1', "Golf [id:{$full->id}]", 18.0, '2026-09-25');                 // ativo + gasto
        $partial = $this->car(90, ['status' => 'sold', 'sold_at' => '2026-09-20 15:00:00']);
        $this->adSpend('A2', "Polo [id:{$partial->id}]", 9.0, '2026-09-25', 'PAUSED');      // só gasto
        $priced = $this->car(300, ['price_gross' => 40000]);
        $this->aggregate($priced, 20000);                                                    // +100% e parada

        $all = collect($this->recs());
        $this->assertSame(100, $all->firstWhere('evidence.car_id', $full->id)['priority']);
        $this->assertSame(95, $all->firstWhere('evidence.car_id', $partial->id)['priority']);
        $this->assertSame([$full->id], $all->where('priority', 100)->pluck('evidence.car_id')->values()->all());
        $this->assertLessThanOrEqual(95, $all->firstWhere('evidence.car_id', $priced->id)['priority']);
    }

    public function test_ties_are_broken_by_euro_impact_then_days_over_threshold(): void
    {
        // Duas viaturas paradas com a MESMA prioridade (dias e vistas iguais), preços diferentes.
        $cheap = $this->car(100, ['price_gross' => 9000]);
        $expensive = $this->car(100, ['price_gross' => 45000]);
        foreach ([$cheap, $expensive] as $c) {
            $this->views($c, 100);
            $this->interaction($c, 'whatsapp_click', 1, 5);
        }

        $dead = $this->recs('automotive_dead_stock');
        $this->assertSame($dead[0]['priority'], $dead[1]['priority']);
        $this->assertSame([$expensive->id, $cheap->id], array_column(array_column($dead, 'evidence'), 'car_id'));
        $this->assertSame(45000.0, $dead[0]['evidence']['impact_eur']);
        $this->assertSame(55, $dead[0]['evidence']['days_over_threshold']);
    }

    public function test_hub_high_count_matches_the_tab_counter_rule(): void
    {
        $sold = $this->car(90, ['status' => 'sold', 'sold_at' => '2026-09-20 15:00:00']);
        $this->adSpend('A1', "Golf [id:{$sold->id}]", 18.0, '2026-09-25');
        $priced = $this->car(100, ['price_gross' => 24000]);
        $this->aggregate($priced, 20000);
        $low = $this->car(20);
        $this->views($low, 3);

        $hub = app(AutomotiveHubService::class)->recommendations($this->company, null, 2);

        // Contador do separador: uma recomendação alta por viatura (a mais prioritária), sobre o motor.
        $byCar = collect($this->recs())->groupBy(fn ($r) => $r['evidence']['car_id'] ?? 'x')->map(fn ($g) => $g->sortByDesc('priority')->first());
        $this->assertSame($byCar->where('level', 'high')->count(), $hub['high_count']);
        $this->assertSame(2, $hub['high_count']);
        $this->assertCount(2, $hub['recommendations']);   // o limite não mexe no contador
    }

    // ── Sem duplicados no hub, configuração e tenancy ───────────────────────

    public function test_hub_shows_one_recommendation_per_car_and_sold_ad_first(): void
    {
        $sold = $this->car(90, ['status' => 'sold', 'sold_at' => '2026-09-20 15:00:00']);
        $this->adSpend('A1', "Golf [id:{$sold->id}]", 18.0, '2026-09-25');
        $priced = $this->car(100, ['price_gross' => 24000]);
        $this->aggregate($priced, 20000);   // +20% e parada → 100

        $recs = app(AutomotiveHubService::class)->recommendations($this->company)['recommendations'];
        $carIds = array_column(array_column($recs, 'evidence'), 'car_id');

        $this->assertSame(count($carIds), count(array_unique($carIds)));
        $this->assertSame('automotive_sold_car_ad_active', $recs[0]['rule_key']);   // empate a 100: a vendida primeiro
    }

    public function test_each_new_rule_can_be_switched_off_per_company(): void
    {
        $car = $this->car(90, ['status' => 'sold', 'sold_at' => '2026-09-20 15:00:00']);
        $this->adSpend('A1', "Golf [id:{$car->id}]", 18.0, '2026-09-25');
        $this->setting('automotive_sold_car_ad_active', [], false);

        $this->assertSame([], $this->recs('automotive_sold_car_ad_active'));
    }

    public function test_tenancy_rules_only_see_their_company_and_endpoint_denies_other_company(): void
    {
        $foreign = $this->car(90, ['status' => 'sold', 'sold_at' => '2026-09-20 15:00:00'], $this->other);
        $this->adSpend('B1', "Golf [id:{$foreign->id}]", 40.0, '2026-09-25', 'ACTIVE', $this->other);
        $foreignStock = $this->car(20, [], $this->other);
        $this->adSpend('B2', "Polo [id:{$foreignStock->id}]", 40.0, '2026-10-01', 'ACTIVE', $this->other);

        $this->assertSame([], $this->recs());                         // empresa A não vê nada de B
        $this->assertCount(1, $this->recs('automotive_sold_car_ad_active', $this->other));
        $this->assertCount(1, $this->recs('automotive_spend_without_lead', $this->other));

        $this->actingAs($this->otherUser, 'sanctum')
            ->getJson("/api/v1/companies/{$this->company->id}/recommendations?vertical=automotive")
            ->assertStatus(403);
    }
}
