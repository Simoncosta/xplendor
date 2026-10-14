<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompassPostSubject;
use App\Models\ContentSector;
use App\Models\EditorialMonth;
use App\Models\EditorialPost;
use App\Models\IneHousingPrice;
use App\Models\Property;
use App\Models\User;
use App\Services\Compass\Imoveis\ImoveisSignalService;
use App\Services\CompanyModuleService;
use App\Services\Editorial\ComplianceWarnings;
use App\Services\Radar\Calendar\OfficialDates;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesAi;
use Tests\TestCase;

/**
 * Bússola Master, R3: o imobiliário (as jogadas, a referência do INE só da freguesia, nunca um
 * preço sugerido, o módulo, a tenancy, sem Finanças, a carteira, o aviso da classe energética),
 * a referência do INE (comando e DICOFRE) e o calendário (camada 2).
 */
class ImoveisCompassTest extends TestCase
{
    use FakesAi;
    use RefreshDatabase;

    private const NOW = '2026-10-14 10:00:00';

    private Company $company;
    private User $admin;
    private int $benfica;
    private int $alvalade;
    private int $semRef;
    private int $planId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::NOW));
        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::NOW));
        Http::preventStrayRequests();
        $this->planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 9, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = $this->makeCompany('500019201', 'Consultor Exemplo');
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
        \App\Access\CompatibilityMigration::run();
        EditorialMonth::create(['company_id' => $this->company->id, 'year' => 2026, 'month' => 10, 'state' => EditorialMonth::OPEN]);

        $district = DB::table('districts')->insertGetId(['name' => 'Lisboa', 'created_at' => now(), 'updated_at' => now()]);
        $lisboa = DB::table('municipalities')->insertGetId(['name' => 'Lisboa', 'district_id' => $district, 'dico' => '1106', 'created_at' => now(), 'updated_at' => now()]);
        $mk = fn (string $name, ?string $code) => DB::table('parishes')->insertGetId(['name' => $name, 'municipality_id' => $lisboa, 'dicofre' => $code, 'created_at' => now(), 'updated_at' => now()]);
        $this->benfica = $mk('Benfica', '110654');
        $this->alvalade = $mk('Alvalade', '110651');
        $this->semRef = $mk('Freguesia Sem Referência', null);
        $ref = fn (string $level, string $geo, string $code, ?int $v) => IneHousingPrice::create(['geo_level' => $level, 'geo_code' => $geo, 'geo_name' => 'x', 'official_code' => $code,
            'period_code' => 'S5A20261', 'period_label' => '1.º Trimestre de 2026', 'median_m2' => $v, 'has_value' => $v !== null, 'ine_updated_on' => '2026-07-17', 'fetched_at' => now()]);
        $ref('freguesia', '1A0110654', '110654', 4000);
        $ref('freguesia', '1A0110651', '110651', 5000);
        $ref('municipio', '1A01106', '1106', 5082);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function makeCompany(string $nipc, string $name, array $modules = ['linha_editorial', 'imoveis']): Company
    {
        $c = Company::create(['nipc' => $nipc, 'fiscal_name' => "{$name} Lda", 'trade_name' => $name, 'plan_id' => $this->planId, 'subscription_status' => 'active',
            'content_sector_id' => ContentSector::where('slug', 'imobiliario')->value('id')]);
        foreach ($modules as $m) {
            app(CompanyModuleService::class)->enable($c->id, $m);
        }

        return $c;
    }

    private function property(array $attrs = [], ?Company $c = null): Property
    {
        static $n = 0;
        $n++;

        return Property::create(array_merge(['company_id' => ($c ?? $this->company)->id, 'reference' => "REF-{$n}", 'title' => "Imóvel {$n}", 'kind' => 'apartamento',
            'typology' => 'T2', 'area_m2' => 100, 'parish_id' => $this->benfica, 'listing' => 'venda', 'price' => 400000, 'energy_class' => 'C', 'status' => 'disponivel',
            'listed_at' => now()->subDays(20)->toDateString()], $attrs));
    }

    private function page(string $q = '', ?User $u = null, ?Company $c = null)
    {
        return $this->actingAs($u ?? $this->admin, 'sanctum')->getJson('/api/v1/companies/' . ($c ?? $this->company)->id . '/marketing/bussola-imoveis' . $q);
    }

    private function signals(): array
    {
        app(ImoveisSignalService::class)->compute($this->company->id);

        return DB::table('compass_signals')->where('provider', 'imobiliario')->where('company_id', $this->company->id)->pluck('signal_key')->sort()->values()->all();
    }

    // ── Sinais e jogadas ─────────────────────────────────────────────────────

    public function test_each_signal_and_the_thresholds(): void
    {
        $new = $this->property(['listed_at' => now()->subDays(3)->toDateString()]);
        $staleSale = $this->property(['listed_at' => now()->subDays(95)->toDateString()]);
        $freshSale = $this->property(['listed_at' => now()->subDays(80)->toDateString()]);
        $staleRent = $this->property(['listing' => 'arrendamento', 'price' => 1200, 'listed_at' => now()->subDays(50)->toDateString()]);
        $closed = $this->property(['status' => 'fechado', 'listed_at' => now()->subDays(100)->toDateString(), 'closed_at' => now()->subDays(5)->toDateString()]);
        $hot = $this->property(['contacts_30' => 4, 'visits_30' => 2, 'interest_updated_at' => now()->subDays(2)->toDateString()]);
        $expected = ["closed:{$closed->id}", "interest:{$hot->id}", "new:{$new->id}", "review_price:{$staleRent->id}", "review_price:{$staleSale->id}", "zone:{$this->benfica}"];
        sort($expected);
        $this->assertSame($expected, $this->signals());
        $this->assertNotContains("review_price:{$freshSale->id}", $this->signals(), '80 dias na venda ainda não está parado (90)');
    }

    public function test_the_price_compares_only_with_the_parish_reference_and_never_suggests_a_price(): void
    {
        // 600 000 / 100 m² = 6 000 por m²; Benfica 4 000: +50%.
        $above = $this->property(['price' => 600000, 'listed_at' => now()->subDays(120)->toDateString()]);
        $noParishRef = $this->property(['parish_id' => $this->semRef, 'listed_at' => now()->subDays(100)->toDateString()]);
        $this->signals();
        $n = DB::table('compass_signals')->where('signal_key', "review_price:{$above->id}")->value('numbers');
        $n = json_decode((string) $n, true);
        $this->assertSame('above_ref', $n['position']);
        $this->assertEquals(50.0, $n['diff_pct']);
        $other = json_decode((string) DB::table('compass_signals')->where('signal_key', "review_price:{$noParishRef->id}")->value('numbers'), true);
        $this->assertNull($other['position'], 'sem referência da freguesia não se compara');
        $this->assertSame(5082, $other['muni_median_m2'], 'o concelho fica só como nota');

        $body = json_encode($this->page()->assertOk()->json('data'), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('+50,0%', $body);
        $this->assertStringContainsString('só como nota, sem comparar', $body);
        foreach (['sugerimos', 'deve pedir', 'preço sugerido', 'baixe o preço para'] as $never) {
            $this->assertStringNotContainsStringIgnoringCase($never, $body);
        }
    }

    public function test_at_most_three_plays_one_per_type_and_never_a_past_date(): void
    {
        $this->property(['listed_at' => now()->subDays(3)->toDateString()]);
        $this->property(['listed_at' => now()->subDays(120)->toDateString()]);
        $this->property(['status' => 'fechado', 'listed_at' => now()->subDays(100)->toDateString(), 'closed_at' => now()->subDays(5)->toDateString()]);
        $this->property(['contacts_30' => 4, 'interest_updated_at' => now()->subDays(2)->toDateString()]);
        $plays = $this->page()->assertOk()->json('data.plays');
        $this->assertCount(3, $plays);
        $this->assertSame(['publish_new', 'review_price', 'ask_testimonial'], array_column($plays, 'type'));
        foreach ($plays as $p) {
            $this->assertGreaterThanOrEqual('2026-10-14', $p['when']['date']);
        }
        $this->assertSame(['view_property'], $plays[1]['actions'], 'rever o preço não cria publicação');
    }

    public function test_the_bce_play_exists_only_on_the_decision_day(): void
    {
        $this->assertTrue(OfficialDates::isBceDecisionDay(CarbonImmutable::parse('2026-10-29')));
        Carbon::setTestNow(Carbon::parse('2026-10-29 09:00:00'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-29 09:00:00'));
        $this->assertContains('bce:20261029', $this->signals());
        $play = $this->page()->json('data.plays.0');
        $this->assertSame('bce_day', $play['type']);
        Carbon::setTestNow(Carbon::parse('2026-10-30 09:00:00'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-30 09:00:00'));
        $this->assertNotContains('bce:20261030', $this->signals());
    }

    public function test_module_off_tenancy_and_finance(): void
    {
        $this->property(['listed_at' => now()->subDays(3)->toDateString()]);
        $other = $this->makeCompany('500019202', 'Outro Consultor');
        $otherAdmin = User::factory()->create(['company_id' => $other->id, 'role' => 'admin']);
        \App\Access\CompatibilityMigration::run();
        $this->page('', $otherAdmin)->assertStatus(403);
        $this->assertSame([], $this->page('', $otherAdmin, $other)->assertOk()->json('data.plays'));

        $noModule = $this->makeCompany('500019203', 'Sem Imóveis', ['linha_editorial']);
        $u = User::factory()->create(['company_id' => $noModule->id, 'role' => 'admin']);
        \App\Access\CompatibilityMigration::run();
        $this->page('', $u, $noModule)->assertStatus(403);

        $noFin = \App\Models\PermissionProfile::create(['company_id' => $this->company->id, 'side' => 'cliente', 'name' => 'Sem Finanças', 'is_system' => false, 'is_suggestion' => false]);
        $noFin->syncPermissions(['bussola.ver', 'editorial.ver']);
        $mkt = User::factory()->create(['company_id' => $this->company->id, 'role' => 'user']);
        $mkt->forceFill(['profile_id' => $noFin->id])->save();
        $numbers = collect($this->page('', $mkt)->assertOk()->json('data.top.numbers'))->keyBy('kind');
        $this->assertTrue($numbers['portfolio_value']['hidden']);
        $this->assertNull($numbers['portfolio_value']['value']);
        $this->assertSame(1, $numbers['portfolio_count']['value']);
    }

    public function test_create_post_links_the_property_and_the_simulated_ai_text(): void
    {
        $p = $this->property(['listed_at' => now()->subDays(3)->toDateString(), 'energy_class' => null]);
        config(['ai.fake' => true]);
        $this->signals();
        $play = collect($this->page()->json('data.plays'))->firstWhere('type', 'publish_new');
        $this->assertSame('ai', $play['what']['source'], 'a frase da IA simulada');
        $this->assertSame($p->id, $play['items'][0]['property_id']);
        $res = $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/companies/{$this->company->id}/marketing/bussola-imoveis/post", [
            'play_key' => $play['key'], 'title' => 'Imóvel novo em Benfica', 'publish_date' => '2026-10-15', 'networks' => ['instagram'], 'format' => 'Carrossel',
        ])->assertOk();
        $post = EditorialPost::findOrFail($res->json('data.post.id'));
        $this->assertTrue(CompassPostSubject::where('editorial_post_id', $post->id)->where('subject_type', 'property')->where('subject_id', $p->id)->exists());

        // A classe energética: em falta, o aviso aparece (sem bloquear).
        $this->assertSame(["Falta a classe energética do imóvel {$p->reference}: é obrigatória em qualquer anúncio de venda ou arrendamento (Decreto-Lei 101-D/2020, artigo 18.º)."],
            ComplianceWarnings::for($post)['warnings']);
        $p->update(['energy_class' => 'B']);
        $version = new \App\Models\EditorialPostVersion(['caption' => 'T2 renovado em Benfica, com varanda.']);
        $this->assertStringContainsString('A legenda não refere a classe energética (B)', ComplianceWarnings::for($post, $version)['warnings'][0]);
        $version->caption = 'T2 renovado. Classe energética B.';
        $this->assertSame([], ComplianceWarnings::for($post, $version)['warnings']);
        $plain = EditorialPost::create(['company_id' => $this->company->id, 'title' => 'Sem imóvel', 'publish_date' => '2026-10-20', 'channel' => 'social', 'stage' => 'idea', 'format' => 'Carrossel']);
        $this->assertSame(['warnings' => []], ComplianceWarnings::for($plain));
    }

    public function test_exclude_and_ignore(): void
    {
        $p = $this->property(['listed_at' => now()->subDays(3)->toDateString()]);
        $this->signals();
        $base = "/api/v1/companies/{$this->company->id}/marketing/bussola-imoveis";
        $this->actingAs($this->admin, 'sanctum')->postJson("{$base}/exclude", ['key' => "new:{$p->id}"])->assertOk()->assertJsonPath('data.property_id', $p->id);
        $this->assertNotContains('publish_new', array_column($this->page()->json('data.plays'), 'type'));
        $this->actingAs($this->admin, 'sanctum')->postJson("{$base}/ignore", ['keys' => ["zone:{$this->benfica}"]])->assertOk();
        $this->assertNotContains('show_zone', array_column($this->page()->json('data.plays'), 'type'));
    }

    // ── A carteira ───────────────────────────────────────────────────────────

    public function test_properties_crud_with_validation_and_interest_date(): void
    {
        $url = "/api/v1/companies/{$this->company->id}/properties";
        $ok = ['reference' => 'A-1', 'title' => 'T2 em Benfica', 'kind' => 'apartamento', 'typology' => 'T2', 'area_m2' => 85, 'parish_id' => $this->benfica,
            'listing' => 'venda', 'price' => 350000, 'energy_class' => 'C', 'status' => 'disponivel', 'listed_at' => '2026-10-01', 'contacts_30' => 2, 'visits_30' => 1];
        $id = $this->actingAs($this->admin, 'sanctum')->postJson($url, $ok)->assertCreated()->assertJsonPath('data.interest_updated_at', '2026-10-14')
            ->assertJsonPath('data.ine.median_m2', 4000)->json('data.id');
        $this->actingAs($this->admin, 'sanctum')->postJson($url, $ok)->assertStatus(422)->assertJsonPath('errors.reference.0', 'Já existe um imóvel com esta referência.');
        $this->actingAs($this->admin, 'sanctum')->postJson($url, ['listed_at' => '2026-12-01', 'reference' => 'A-2'] + $ok)->assertStatus(422);
        $this->actingAs($this->admin, 'sanctum')->putJson("{$url}/{$id}", ['status' => 'fechado'] + $ok)->assertStatus(422);
        $this->actingAs($this->admin, 'sanctum')->putJson("{$url}/{$id}", ['status' => 'fechado', 'closed_at' => '2026-10-10'] + $ok)->assertOk()->assertJsonPath('data.status', 'fechado');
        $this->actingAs($this->admin, 'sanctum')->getJson($url)->assertOk()->assertJsonCount(1, 'data.properties');
        $this->actingAs($this->admin, 'sanctum')->deleteJson("{$url}/{$id}")->assertOk();

        $other = $this->makeCompany('500019204', 'Terceiro');
        $foreign = $this->property([], $other);
        $this->actingAs($this->admin, 'sanctum')->getJson("{$url}/{$foreign->id}")->assertStatus(404);
    }

    // ── A referência do INE e o DICOFRE ──────────────────────────────────────

    public function test_ine_command_simulates_without_requests_and_saves_from_a_sample_once(): void
    {
        IneHousingPrice::query()->delete();
        $this->artisan('ine:housing-prices')->expectsOutputToContain('SIMULAÇÃO: nenhum pedido')->assertSuccessful();
        Http::assertNothingSent();
        $this->artisan('ine:housing-prices', ['--fixture' => 'tests/Fixtures/ine/pindica-0012234-S5A20261.json'])->assertSuccessful();
        $this->assertSame(0, IneHousingPrice::count(), 'com amostra e sem --execute não grava');
        $this->artisan('ine:housing-prices', ['--fixture' => 'tests/Fixtures/ine/pindica-0012234-S5A20261.json', '--execute' => true])->assertSuccessful();
        $ajuda = IneHousingPrice::where('official_code', '110601')->firstOrFail();
        $this->assertSame(['freguesia', 'S5A20261', '1.º Trimestre de 2026'], [$ajuda->geo_level, $ajuda->period_code, $ajuda->period_label]);
        $this->assertSame(5082, IneHousingPrice::where('official_code', '1106')->value('median_m2'));
        $this->assertTrue(IneHousingPrice::where('has_value', false)->exists(), 'sem valor publicado: nunca zero');

        // Com o INE (simulado): a metainformação dá o último período; um período já gravado não se repete.
        Http::fake(['www.ine.pt/ine/json_indicador/pindicaMeta.jsp*' => Http::response(file_get_contents(base_path('tests/Fixtures/ine/pindicaMeta-0012234.json')), 200)]);
        $this->artisan('ine:housing-prices', ['--execute' => true])->expectsOutputToContain('já está gravado')->assertSuccessful();
        Http::assertSentCount(1);
    }

    public function test_the_official_parish_list_and_the_dicofre_columns_exist(): void
    {
        $this->assertSame(3092, DB::table('official_parishes')->count());
        $this->assertSame(584, DB::table('official_parishes')->where('in_nuts_2024', true)->count());
        $this->assertSame('Ajuda', DB::table('official_parishes')->where('dicofre', '110601')->value('name'));
        $migration = require database_path('migrations/2027_01_04_100000_add_dicofre_to_parishes.php');
        $this->assertSame('aguada baixo barro', $migration::loose('União das freguesias de Aguada de Baixo e Barrô'));

        // O painel do root lista as freguesias sem código e as da lista oficial que a plataforma não tem.
        $root = User::factory()->create(['company_id' => $this->company->id, 'role' => 'root']);
        $ine = $this->actingAs($root, 'sanctum')->getJson('/api/v1/admin/radar')->assertOk()->json('data.ine');
        $this->assertSame(3092, $ine['parishes']['official']);
        $this->assertSame(2, $ine['parishes']['with_code']);
        $this->assertSame(['Freguesia Sem Referência'], array_column($ine['parishes']['without_code'], 'name'));
        $present = DB::table('official_parishes')->whereIn('dicofre', ['110654', '110651'])->count();
        $this->assertSame(3092 - $present, $ine['parishes']['official_missing_count']);
    }

    // ── O calendário (camada 2) ──────────────────────────────────────────────

    public function test_the_calendar_entries_by_sector_and_municipality_and_react_to_a_date(): void
    {
        $keys = fn (array $slugs, ?string $mun, string $at) => array_map(fn ($e) => "{$e['key']}@{$e['occurrence']}", OfficialDates::upcoming($slugs, $mun, CarbonImmutable::parse($at)));
        $this->assertContains('bce.monetary_policy_decision@2026-10-29', $keys(['universal', 'imobiliario'], 'Lisboa', '2026-10-14'));
        $this->assertContains('ine.precos_habitacao_local@2026-10-23', $keys(['universal', 'imobiliario'], 'Lisboa', '2026-10-14'));
        $this->assertNotContains('bce.monetary_policy_decision@2026-10-29', $keys(['restauracao'], 'Lisboa', '2026-10-14'));
        $this->assertContains('fiscal.imi.prestacoes@2027-05-01', $keys(['imobiliario'], null, '2027-05-10'));
        $this->assertContains('fiscal.irs.entrega@2027-04-01', $keys(['automovel', 'carros'], null, '2027-04-02'));
        $this->assertNotContains('fiscal.irs.entrega@2027-04-01', $keys(['restauracao'], null, '2027-04-02'), 'o IRS fica fora da restauração');
        $this->assertContains('local.porto.sao_joao@2027-06-23', $keys(['restauracao'], 'Porto', '2027-06-01'));
        $this->assertNotContains('local.porto.sao_joao@2027-06-23', $keys(['restauracao'], 'Lisboa', '2027-06-01'));
        // Já começou e ainda não acabou: o "quando" é hoje, nunca uma data passada.
        $irs = collect(OfficialDates::upcoming(['imobiliario'], null, CarbonImmutable::parse('2027-05-15')))->firstWhere('key', 'fiscal.irs.entrega');
        $this->assertSame('2027-05-15', $irs['when']['date']);

        app(CompanyModuleService::class)->enable($this->company->id, 'radar');
        $url = "/api/v1/companies/{$this->company->id}/marketing/radar";
        $cal = collect($this->actingAs($this->admin, 'sanctum')->getJson($url)->assertOk()->json('data.calendar'));
        $entry = $cal->firstWhere('key', 'ine.precos_habitacao_local');
        $this->assertSame('INE, calendário de difusão', $entry['source']['name']);
        $body = ['calendar_key' => $entry['key'], 'occurrence' => $entry['occurrence'], 'title' => 'Preços da habitação: o que diz o INE', 'publish_date' => '2026-10-23',
            'networks' => ['instagram'], 'format' => 'Carrossel'];
        $id = $this->actingAs($this->admin, 'sanctum')->postJson("{$url}/react", $body)->assertOk()->json('data.post.id');
        $post = EditorialPost::findOrFail($id);
        $this->assertSame(['calendario', 'ine.precos_habitacao_local@2026-10-23', '2026-10-23'], [$post->origin, $post->origin_ref, $post->valid_until->toDateString()]);
        $this->assertSame(0, DB::table('editorial_post_versions')->where('editorial_post_id', $id)->count(), 'sem texto');
        $this->actingAs($this->admin, 'sanctum')->postJson("{$url}/react", ['title' => 'Outra'] + $body)->assertStatus(422)
            ->assertJsonPath('errors.calendar_key.0', 'Já existe uma ideia para esta data.');
        $this->assertTrue(collect($this->actingAs($this->admin, 'sanctum')->getJson($url)->json('data.calendar'))->firstWhere('key', 'ine.precos_habitacao_local')['reacted']);
    }
}
