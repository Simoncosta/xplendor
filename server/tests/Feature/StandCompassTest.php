<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RecomputeStandCompassJob;
use App\Models\Car;
use App\Models\CarBrand;
use App\Models\CarMarketAggregate;
use App\Models\CarModel;
use App\Models\Company;
use App\Models\CompassPostSubject;
use App\Models\CompassSignal;
use App\Models\EditorialMonth;
use App\Models\EditorialPost;
use App\Models\MetaAd;
use App\Models\MetaAdInsightDaily;
use App\Models\PermissionProfile;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\Compass\Stand\StandCompassService;
use App\Services\Compass\Stand\StandSignalService;
use App\Services\MetaAdCarAllocator;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\FakesAi;
use Tests\TestCase;

/**
 * Bússola do stand (parte 3): cada tipo de jogada, o agrupamento, a prioridade (primeiro o
 * dinheiro a perder), nenhuma data passada, o preço só com comparação exata, os limiares de
 * dias por tipo, a exclusão de um veículo, o ignorar, o interruptor, a tenancy, sem Finanças
 * nenhum valor financeiro, nenhum dado pessoal, criar publicação e a frase da IA simulada.
 * Hoje é quinta-feira, 08/10/2026.
 */
class StandCompassTest extends TestCase
{
    use RefreshDatabase;
    use FakesAi;

    private const NOW = '2026-10-08 10:00:00';

    private Company $company;
    private User $admin;
    private int $brandId;
    private int $modelId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::NOW));
        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::NOW));
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500019001', 'fiscal_name' => 'Stand Exemplo Lda', 'trade_name' => 'Stand Exemplo', 'plan_id' => $planId, 'subscription_status' => 'active']);
        foreach (['stock', 'linha_editorial'] as $m) {
            app(CompanyModuleService::class)->enable($this->company->id, $m);
        }
        $this->company->forceFill(['bussola_stand_enabled' => true])->save();
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
        $brand = CarBrand::firstOrCreate(['slug' => 'peugeot'], ['name' => 'Peugeot', 'vehicle_type' => 'car']);
        $this->brandId = $brand->id;
        $this->modelId = CarModel::firstOrCreate(['name' => '208', 'car_brand_id' => $brand->id])->id;
        \App\Access\CompatibilityMigration::run();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    // ── Auxiliares ───────────────────────────────────────────────────────────

    private function car(int $daysOld, array $attrs = [], ?Company $company = null): Car
    {
        $car = Car::factory()->create(array_merge([
            'company_id' => ($company ?? $this->company)->id, 'car_brand_id' => $this->brandId, 'car_model_id' => $this->modelId,
            'vehicle_type' => 'car', 'status' => 'active', 'is_resume' => 0, 'price_gross' => 20000, 'promo_price_gross' => null,
            'registration_year' => 2019, 'mileage_km' => 85000,
        ], array_diff_key($attrs, ['sold_at' => 1])));
        DB::table('cars')->where('id', $car->id)->update(['created_at' => now()->startOfDay()->subDays($daysOld), 'car_created_at' => null] + (array_key_exists('sold_at', $attrs) ? ['sold_at' => $attrs['sold_at']] : []));

        return $car->fresh();
    }

    private function aggregate(Car $car, float $median, string $confidence = 'high', bool $fallback = false, ?string $method = 'car_v1', int $comparables = 12): void
    {
        CarMarketAggregate::create(['car_id' => $car->id, 'vehicle_type' => $car->vehicle_type, 'status' => 'success', 'confidence' => $confidence,
            'comparables_count' => $comparables, 'median_price' => $median, 'fallback_used' => $fallback, 'method' => $method]);
    }

    private function views(Car $car, int $n, int $daysAgo = 1): void
    {
        DB::table('car_views')->insert(array_fill(0, $n, ['company_id' => $car->company_id, 'car_id' => $car->id, 'ip_address' => '127.0.0.1',
            'created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo)]));
    }

    private function adSpend(string $adId, string $name, float $spend, string $date, string $status = 'ACTIVE'): void
    {
        MetaAdInsightDaily::create(['company_id' => $this->company->id, 'account_id' => '123', 'date' => $date, 'campaign_id' => 'C1',
            'adset_id' => 'S1', 'ad_id' => $adId, 'ad_name' => $name, 'spend' => $spend, 'impressions' => 100, 'clicks' => 3]);
        MetaAd::updateOrCreate(['company_id' => $this->company->id, 'account_id' => '123', 'ad_id' => $adId],
            ['ad_name' => $name, 'campaign_id' => 'C1', 'effective_status' => $status]);
        app(MetaAdCarAllocator::class)->rebuild($this->company->id, '123');
    }

    private function compute(): void
    {
        app(StandSignalService::class)->compute($this->company->id);
    }

    private function page(?User $user = null, string $query = ''): array
    {
        return $this->actingAs($user ?? $this->admin, 'sanctum')->getJson("/api/v1/companies/{$this->company->id}/marketing/bussola-stand{$query}")->assertOk()->json('data');
    }

    /** Um veículo de cada jogada: vendido com anúncio, preço acima e parado, dois parados, muitas vistas, preço alinhado. */
    private function everyPlay(): array
    {
        $sold = $this->car(80, ['status' => 'sold', 'sold_at' => now()->subDays(5)]);
        $this->adSpend('A1', "Peugeot 208 [id:{$sold->id}]", 40.0, now()->subDays(2)->toDateString());
        $price = $this->car(50, ['price_gross' => 22400]);
        $this->aggregate($price, 20000);                       // +12%, exata, confiança alta
        $staleA = $this->car(70);
        $staleB = $this->car(60);
        $views = $this->car(10);
        $this->views($views, 70);
        $stage = $this->car(12, ['price_gross' => 19000]);
        $this->aggregate($stage, 20000);                       // −5%: abaixo do mercado

        return compact('sold', 'price', 'staleA', 'staleB', 'views', 'stage');
    }

    // ── Jogadas ──────────────────────────────────────────────────────────────

    public function test_every_play_type_and_the_priority_money_first_three_at_most(): void
    {
        $cars = $this->everyPlay();
        $this->compute();
        $svc = app(StandCompassService::class);
        $today = CarbonImmutable::parse(self::NOW)->startOfDay();
        $groups = $svc->candidateGroups($svc->visibleSignals($this->company->id, 'all', $today));

        $this->assertSame(['stop_ad', 'review_price', 'renew_stock', 'use_interest', 'give_stage'], array_keys($groups));
        $this->assertSame([$cars['views']->id], $groups['use_interest'][0]['members']->pluck('subject_id')->all());
        $this->assertSame([$cars['stage']->id], $groups['give_stage'][0]['members']->pluck('subject_id')->all());

        $plays = $this->page()['plays'];
        $this->assertSame(['stop_ad', 'review_price', 'renew_stock'], array_column($plays, 'type'), 'primeiro o dinheiro a perder');
        $stop = $plays[0];
        $this->assertSame("Parar o anúncio de Peugeot 208 (2019)", $stop['title']);
        $this->assertSame($cars['sold']->id, $stop['car_id']);
        $this->assertSame(['view_car'], $stop['actions']);
        $this->assertStringContainsString('Gestor de Anúncios', $stop['where']['ad']);
        $price = $plays[1];
        $this->assertSame(['+12%', 'acima da mediana dos comparáveis'], [$price['number']['value'], $price['number']['caption']]);
        $this->assertSame('50 dias, +12%, mediana de 20 000 € (12 comparáveis)', $price['items'][0]['detail'], 'os dias, a % acima e a mediana com os comparáveis');
        $this->assertSame(['review_price', 'view_car'], $price['actions']);
        $this->assertStringNotContainsStringIgnoringCase('preço recomendado', json_encode($plays, JSON_UNESCAPED_UNICODE), 'nunca sugere um preço');
        $renew = $plays[2];
        $this->assertSame(['Renovar o stock parado', 2, null], [$renew['title'], count($renew['items']), $renew['car_id']]);
        $this->assertSame([$cars['staleA']->id, $cars['staleB']->id], array_column($renew['items'], 'car_id'), 'os mais antigos primeiro');

        // Com as autocaravanas no seletor, não há jogadas (só carros).
        $this->assertSame([], $this->page(null, '?type=motorhome')['plays']);
    }

    public function test_vehicles_with_the_same_signal_join_in_one_play(): void
    {
        $a = $this->car(50, ['price_gross' => 22000]);
        $this->aggregate($a, 20000);   // +10%
        $b = $this->car(90, ['price_gross' => 26000]);
        $this->aggregate($b, 20000);   // +30%
        $this->compute();

        $play = collect($this->page()['plays'])->firstWhere('type', 'review_price');
        $this->assertSame('Rever o preço de 2 veículos', $play['title']);
        $this->assertSame([$b->id, $a->id], array_column($play['items'], 'car_id'), 'o maior desvio primeiro');
        $this->assertSame(['+30%', 'o maior desvio, entre 2 veículos'], [$play['number']['value'], $play['number']['caption']]);
        $this->assertNull($play['car_id']);
        $this->assertCount(2, $play['signal_keys']);
    }

    public function test_when_is_never_a_past_date(): void
    {
        $this->everyPlay();
        $this->compute();
        Carbon::setTestNow(Carbon::parse('2026-10-11 09:00:00')); // três dias depois, sem recalcular
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-11 09:00:00'));

        foreach ($this->page()['plays'] as $p) {
            $this->assertGreaterThanOrEqual('2026-10-11', $p['when']['date'], $p['type']);
        }
        $stop = collect($this->page()['plays'])->firstWhere('type', 'stop_ad');
        $this->assertSame(['2026-10-11', true, 'Fazer hoje'], [$stop['when']['date'], $stop['when']['is_today'], $stop['when']['label']]);
    }

    public function test_price_only_with_an_exact_comparison_and_enough_confidence(): void
    {
        $approx = $this->car(130, ['vehicle_type' => 'motorhome', 'price_gross' => 60000]);
        $this->aggregate($approx, 40000, 'medium', true, null);     // aproximada (pode ter largado o modelo)
        $low = $this->car(130, ['price_gross' => 30000]);
        $this->aggregate($low, 20000, 'low');                         // confiança baixa
        $none = $this->car(10, ['vehicle_type' => 'caravan']);        // sem mercado
        $this->compute();
        $page = $this->page();

        $this->assertNull(collect($page['plays'])->firstWhere('type', 'review_price'), 'nenhuma comparação de preço conta');
        $this->assertSame(0, CompassSignal::where('type', 'review_price')->count());
        $this->assertSame(0, CompassSignal::where('type', 'stage')->count());
        $this->assertSame(['aproximada' => 1, 'confianca_baixa' => 1], array_intersect_key($page['blocks']['price']['outside'], ['aproximada' => 1, 'confianca_baixa' => 1]));
        $this->assertSame(0, $page['blocks']['price']['eligible']);
        $above = collect($page['top']['numbers'])->firstWhere('kind', 'above_market');
        $this->assertSame([null, 'de 0 veículos com comparação exata'], [$above['value'], $above['caption']]);
        $this->assertStringContainsString('fora das comparações de preço', implode(' ', $page['top']['notes']));
        $this->assertNotNull($none);
    }

    public function test_the_days_threshold_is_per_vehicle_type(): void
    {
        $car44 = $this->car(44);
        $car45 = $this->car(45);
        $mh119 = $this->car(119, ['vehicle_type' => 'motorhome']);
        $mh120 = $this->car(120, ['vehicle_type' => 'motorhome']);
        $this->compute();

        $stale = CompassSignal::where('type', 'stale')->pluck('subject_id')->sort()->values()->all();
        $this->assertSame([$car45->id, $mh120->id], $stale, '45 dias nos carros, 120 nas autocaravanas');
        $this->assertNotContains($car44->id, $stale);
        $this->assertNotContains($mh119->id, $stale);
        $aging = $this->page()['blocks']['aging'];
        $this->assertSame([['car', 45], ['motorhome', 120]], array_map(fn ($g) => [$g['group'], $g['threshold']], $aging['groups']));
        // Cada seletor só com o seu tipo: dois parados de tipos diferentes não fazem um grupo.
        $this->assertNull(collect($this->page(null, '?type=car')['plays'])->firstWhere('type', 'renew_stock'));
        $this->assertNotNull(collect($this->page()['plays'])->firstWhere('type', 'renew_stock'));
    }

    // ── Ignorar e exclusão permanente ────────────────────────────────────────

    public function test_a_vehicle_excluded_for_good_leaves_the_plays_and_can_be_included_again(): void
    {
        $a = $this->car(50, ['price_gross' => 22400]);
        $this->aggregate($a, 20000);
        $this->compute();
        $url = "/api/v1/companies/{$this->company->id}/marketing/bussola-stand";
        $this->actingAs($this->admin, 'sanctum')->postJson("{$url}/exclude", ['key' => "review_price:{$a->id}"])->assertOk()->assertJsonPath('data.car_id', $a->id);

        $this->assertNull(collect($this->page()['plays'])->firstWhere('type', 'review_price'));
        app(\App\Services\Compass\Stand\StandCompassActions::class)->include($this->company->id, $this->admin, $a->id);
        $this->assertNotNull(collect($this->page()['plays'])->firstWhere('type', 'review_price'));

        // Ignorar durante 4 semanas: some; restaurar: volta.
        $this->actingAs($this->admin, 'sanctum')->postJson("{$url}/ignore", ['keys' => ["review_price:{$a->id}"]])->assertOk();
        $this->assertNull(collect($this->page()['plays'])->firstWhere('type', 'review_price'));
        $this->actingAs($this->admin, 'sanctum')->postJson("{$url}/restore", ['key' => "review_price:{$a->id}"])->assertOk();
        $this->assertNotNull(collect($this->page()['plays'])->firstWhere('type', 'review_price'));
    }

    // ── Interruptor ──────────────────────────────────────────────────────────

    public function test_the_switch_off_shows_nothing_and_only_the_root_command_turns_it_on(): void
    {
        $this->everyPlay();
        $this->company->forceFill(['bussola_stand_enabled' => false])->save();
        $this->assertSame(0, app(StandSignalService::class)->compute($this->company->id), 'desligada, não calcula');
        $page = $this->page();
        $this->assertSame([false, null, []], [$page['enabled'], $page['top'], $page['plays']], 'desligada, não mostra nada');

        Queue::fake();
        $this->artisan('bussola:stand-switch', ['company' => $this->company->id, 'state' => 'on'])->assertExitCode(0);
        $this->assertTrue((bool) $this->company->fresh()->bussola_stand_enabled);
        Queue::assertPushed(RecomputeStandCompassJob::class);
        // Uma empresa sem o módulo de stock: o interruptor não liga e a rota não existe para ela.
        $planId = DB::table('plans')->value('id');
        $restaurant = Company::create(['nipc' => '500019009', 'fiscal_name' => 'Restaurante', 'plan_id' => $planId, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->applyPreset($restaurant->id, 'restaurant');
        $this->assertFalse(app(CompanyModuleService::class)->isEnabled($restaurant->id, 'stock'));
        $this->artisan('bussola:stand-switch', ['company' => $restaurant->id, 'state' => 'on'])->assertExitCode(1);
        $owner = User::factory()->create(['company_id' => $restaurant->id, 'role' => 'admin']);
        $this->actingAs($owner, 'sanctum')->getJson("/api/v1/companies/{$restaurant->id}/marketing/bussola-stand")->assertForbidden();
    }

    public function test_a_new_company_starts_with_the_switch_off(): void
    {
        $planId = DB::table('plans')->value('id');
        $new = Company::create(['nipc' => '500019002', 'fiscal_name' => 'Outro Stand', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->assertFalse((bool) $new->fresh()->bussola_stand_enabled);
    }

    // ── Tenancy ──────────────────────────────────────────────────────────────

    public function test_tenancy(): void
    {
        $a = $this->car(50, ['price_gross' => 22400]);
        $this->aggregate($a, 20000);
        $this->compute();
        $planId = DB::table('plans')->value('id');
        $other = Company::create(['nipc' => '500019003', 'fiscal_name' => 'Stand B', 'plan_id' => $planId, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->enable($other->id, 'stock');
        app(CompanyModuleService::class)->enable($other->id, 'linha_editorial');
        $intruder = User::factory()->create(['company_id' => $other->id, 'role' => 'admin']);
        $url = "/api/v1/companies/{$this->company->id}/marketing/bussola-stand";

        $this->actingAs($intruder, 'sanctum')->getJson($url)->assertForbidden();
        $this->actingAs($intruder, 'sanctum')->postJson("{$url}/exclude", ['key' => "review_price:{$a->id}"])->assertForbidden();
        // Na própria empresa, a chave de outra empresa não existe.
        $this->actingAs($intruder, 'sanctum')->postJson("/api/v1/companies/{$other->id}/marketing/bussola-stand/ignore", ['keys' => ["review_price:{$a->id}"]])->assertStatus(422);
    }

    // ── Sem Finanças e sem dados pessoais ────────────────────────────────────

    public function test_without_finance_no_financial_value_and_the_public_prices_stay(): void
    {
        $this->everyPlay();
        $withAd = $this->car(20);
        $this->adSpend('A2', "Peugeot 208 [id:{$withAd->id}]", 80.0, now()->subDays(3)->toDateString());
        DB::table('car_interactions')->insert(['company_id' => $this->company->id, 'car_id' => $withAd->id, 'interaction_type' => 'whatsapp_click',
            'visitor_id' => 'v1', 'session_id' => 's1', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
        $this->compute();
        $profile = PermissionProfile::create(['company_id' => $this->company->id, 'side' => 'cliente', 'name' => 'Marketing sem Finanças', 'is_system' => false, 'is_suggestion' => false]);
        $profile->syncPermissions(['bussola.ver', 'bussola.criar', 'bussola.editar', 'editorial.ver']);
        $marketing = User::factory()->create(['company_id' => $this->company->id, 'role' => 'user', 'profile_id' => $profile->id]);

        $page = $this->page($marketing);
        $this->assertSame(['visible' => false, 'note' => 'Sem acesso aos valores financeiros'], $page['financial']);
        $top = collect($page['top']['numbers'])->keyBy('kind');
        foreach (['stock_value', 'ad_spend', 'cost_per_contact'] as $kind) {
            $this->assertSame([null, true], [$top[$kind]['value'], $top[$kind]['hidden']], $kind);
        }
        $this->assertNotNull($top['stock_count']['value']);
        foreach (array_merge($page['blocks']['ads']['rows'], $page['blocks']['ads']['without_contacts']) as $row) {
            $this->assertSame([null, null, true], [$row['spend'], $row['cost_per_contact'], $row['hidden']]);
        }
        $json = json_encode($page, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('40 €', $json, 'sem o gasto do anúncio');
        $this->assertStringNotContainsString('80 €', $json);
        $this->assertDoesNotMatchRegularExpression('/gasto de \d/', $json);
        // Os preços e as medianas são públicos: ficam.
        $price = collect($page['plays'])->firstWhere('type', 'review_price');
        $this->assertStringContainsString('mediana de 20 000 €', $price['items'][0]['detail']);
        $this->assertSame(22400.0, (float) $page['blocks']['price']['top_above'][0]['price']);

        // Com Finanças, os valores vêm.
        $admin = $this->page();
        $this->assertSame(true, $admin['financial']['visible']);
        $this->assertSame(12000, collect($admin['top']['numbers'])->firstWhere('kind', 'ad_spend')['value'], '120 € em cêntimos');
        $this->assertSame(12000, collect($admin['top']['numbers'])->firstWhere('kind', 'cost_per_contact')['value'], 'um contacto nos veículos com gasto (os vendidos contam no gasto)');
    }

    public function test_no_personal_data_in_the_answer(): void
    {
        $cars = $this->everyPlay();
        DB::table('car_leads')->insert(['name' => 'Joana Cliente Secreta', 'email' => 'joana.secreta@exemplo.pt', 'phone' => '912345678', 'car_id' => $cars['views']->id,
            'company_id' => $this->company->id, 'status' => 'new', 'channel' => 'organic', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
        DB::table('car_interactions')->insert(['company_id' => $this->company->id, 'car_id' => $cars['stage']->id, 'interaction_type' => 'call_click',
            'phone' => '934567890', 'visitor_id' => 'v9', 'session_id' => 's9', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
        $this->compute();

        $json = json_encode([$this->page(), $this->page(null, '?plays_only=1')], JSON_UNESCAPED_UNICODE);
        foreach (['Joana', 'Secreta', 'joana.secreta', '912345678', '934567890', '@exemplo.pt'] as $personal) {
            $this->assertStringNotContainsString($personal, $json);
        }
        $this->assertStringNotContainsString('"email"', $json);
        $this->assertStringNotContainsString('"phone"', $json);
    }

    // ── Criar publicação, sugerir texto e a IA ───────────────────────────────

    public function test_create_post_from_a_play_links_the_vehicle_and_never_takes_a_past_date(): void
    {
        $stage = $this->car(12, ['price_gross' => 19000]);
        $this->aggregate($stage, 20000);
        $this->compute();
        EditorialMonth::create(['company_id' => $this->company->id, 'year' => 2026, 'month' => 10, 'state' => EditorialMonth::OPEN]);
        $play = collect($this->page()['plays'])->firstWhere('type', 'give_stage');
        $this->assertStringContainsString('Sem os Ficheiros ligados', $play['post_note']);
        $url = "/api/v1/companies/{$this->company->id}/marketing/bussola-stand/post";
        $body = ['play_key' => $play['key'], 'title' => 'Peugeot 208 em destaque', 'networks' => ['instagram', 'facebook'], 'format' => 'Carrossel'];

        $this->actingAs($this->admin, 'sanctum')->postJson($url, $body + ['publish_date' => '2026-10-07'])->assertStatus(422);
        $res = $this->actingAs($this->admin, 'sanctum')->postJson($url, $body + ['publish_date' => '2026-10-09'])->assertOk();
        $post = EditorialPost::findOrFail($res->json('data.post.id'));
        $this->assertSame([EditorialPost::STAGE_IDEA, 0], [$post->stage, $res->json('data.photos')]);
        $this->assertSame([$stage->id], CompassPostSubject::where('editorial_post_id', $post->id)->pluck('subject_id')->all());

        // Com a publicação feita, o veículo deixa de ser "sem publicação nos últimos 30 dias".
        $this->compute();
        $this->assertNull(collect($this->page()['plays'])->firstWhere('type', 'give_stage'));
    }

    public function test_who_can_act_and_the_agency_guest_sees_without_euros(): void
    {
        $this->everyPlay();
        $this->compute();
        $guestProfile = PermissionProfile::where('system_key', \App\Access\ProfileSuggestions::GUEST_AGENCY)->firstOrFail();
        $copy = PermissionProfile::create(['company_id' => $this->company->id, 'side' => 'cliente', 'name' => 'Agência convidada', 'is_system' => false, 'is_suggestion' => false]);
        $copy->syncPermissions(\App\Models\ProfilePermission::where('profile_id', $guestProfile->id)->get()->map(fn ($p) => "{$p->area}.{$p->action}")->all());
        $guest = User::factory()->create(['company_id' => $this->company->id, 'role' => 'user', 'profile_id' => $copy->id]);

        $page = $this->page($guest);
        $this->assertFalse($page['financial']['visible'], 'a agência convidada vê, sem os euros');
        $this->assertNotEmpty($page['plays']);
        // Sem a Linha Editorial, ninguém cria nem ignora.
        app(CompanyModuleService::class)->disable($this->company->id, 'linha_editorial');
        $page = $this->page();
        $this->assertSame([false, 'A Linha Editorial não está ativa nesta empresa.'], [$page['can_act'], $page['can_act_reason']]);
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/companies/{$this->company->id}/marketing/bussola-stand/ignore", ['keys' => ['x']])->assertForbidden();
    }

    public function test_what_comes_from_the_ai_with_the_brand_profile_or_falls_back_to_the_template(): void
    {
        $this->configureAi();
        $a = $this->car(50, ['price_gross' => 22400]);
        $this->aggregate($a, 20000);
        \App\Models\CompanyBrandProfile::create(['company_id' => $this->company->id, 'tone_of_voice' => 'Próximo e direto.', 'words_to_avoid' => ['imperdível']]);
        $this->fakeAi(['jogadas' => [
            ['chave' => "review_price:all#{$this->textHash(["review_price:{$a->id}"])}", 'o_que' => 'Reveja o preço do Peugeot face aos veículos semelhantes no mercado.'],
            ['chave' => "review_price:car#{$this->textHash(["review_price:{$a->id}"])}", 'o_que' => 'Baixe o preço em 2000 euros.'], // números: recusada
        ]]);
        $this->compute();

        $prompt = $this->sentAiPrompt();
        $this->assertStringContainsString('stand de veículos', $prompt);
        $this->assertStringContainsString('Próximo e direto.', $prompt);
        $this->assertStringContainsString('Peugeot 208, 2019, 85 000 km, 22 400 €', $prompt);
        $all = collect($this->page()['plays'])->firstWhere('type', 'review_price');
        $this->assertSame(['ai', 'Reveja o preço do Peugeot face aos veículos semelhantes no mercado.'], [$all['what']['source'], $all['what']['text']]);
        $car = collect($this->page(null, '?type=car')['plays'])->firstWhere('type', 'review_price');
        $this->assertSame(['template', StandCompassService::TEMPLATES['review_price']], [$car['what']['source'], $car['what']['text']]);

        // Com outro veículo no grupo (sem recalcular as frases), a frase antiga não serve: falaria de um só veículo.
        $b = $this->car(60, ['price_gross' => 25000]);
        $this->aggregate($b, 20000);
        \App\Models\CompassText::query()->update(['generated_at' => now()]); // as frases ficam; os sinais mudam
        Http::fake();
        app(StandSignalService::class)->compute($this->company->id);
        $all = collect($this->page()['plays'])->firstWhere('type', 'review_price');
        $this->assertSame([2, 'template'], [count($all['items']), $all['what']['source']]);
    }

    private function textHash(array $keys): string
    {
        sort($keys);

        return substr(md5(implode('|', $keys)), 0, 12);
    }

    public function test_the_signals_are_recomputed_when_stock_prices_or_market_change(): void
    {
        $car = $this->car(10);
        Queue::fake();
        $car->update(['price_gross' => 18000]);
        Queue::assertPushed(RecomputeStandCompassJob::class, fn ($j) => $j->companyId === $this->company->id);
        Queue::fake();
        $car->update(['description_website_pt' => 'Outra descrição.']);
        Queue::assertNotPushed(RecomputeStandCompassJob::class);
        $this->aggregate($car, 19000);
        Queue::assertPushed(RecomputeStandCompassJob::class);
        // Empresa com o interruptor desligado: nada.
        Queue::fake();
        $this->company->forceFill(['bussola_stand_enabled' => false])->save();
        $car->update(['price_gross' => 17000]);
        Queue::assertNotPushed(RecomputeStandCompassJob::class);
    }

    public function test_the_dashboard_gets_only_the_plays_by_type(): void
    {
        $this->everyPlay();
        $mh = $this->car(130, ['vehicle_type' => 'motorhome']);
        $this->car(125, ['vehicle_type' => 'caravan']);
        $this->compute();

        $plays = $this->page(null, '?plays_only=1&type=motorhome');
        $this->assertNull($plays['top']);
        $this->assertArrayNotHasKey('blocks', $plays);
        $this->assertSame(['renew_stock'], array_column($plays['plays'], 'type'), 'as caravanas contam como autocaravanas');
        $this->assertContains($mh->id, array_column($plays['plays'][0]['items'], 'car_id'));
    }
}
