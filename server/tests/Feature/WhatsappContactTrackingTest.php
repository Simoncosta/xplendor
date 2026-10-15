<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Car;
use App\Models\CarAdAttribution;
use App\Models\CarInteraction;
use App\Models\CarLead;
use App\Models\Company;
use App\Repositories\CarInteractionRepository;
use App\Repositories\DashboardRepository;
use App\Services\Automotive\AutomotiveMarketingService;
use App\Services\CompanyModuleService;
use App\Support\Tracking\WhatsappContact;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * XP-WA-FIX: eventos de WhatsApp vindos dos sites.
 *
 * Regra única (App\Support\Tracking\WhatsappContact): contacto por WhatsApp = whatsapp_send +
 * whatsapp_click que não seja só a abertura do modal (page_context floating_button). Cobre o
 * /track (aceitar whatsapp_send, viatura de outra empresa sem viatura, deduplicação com o
 * page_context, registo das recusas) e os leitores (marketing do automóvel, dashboard, vista
 * do funil, análise do veículo e atribuição).
 */
class WhatsappContactTrackingTest extends TestCase
{
    use RefreshDatabase;

    private const EVENT_AT = '2026-10-05 11:00:00';

    private const NOW = '2026-10-16 12:00:00';   // o mês do marketing: 01/10 a 15/10

    private Company $company;

    private Company $other;

    private Car $car;

    private Car $otherCar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at(self::EVENT_AT);

        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500000901', 'fiscal_name' => 'Quebom', 'plan_id' => $planId,
            'subscription_status' => 'active', 'public_api_token' => (string) Str::uuid()]);
        $this->other = Company::create(['nipc' => '500000902', 'fiscal_name' => 'Outra', 'plan_id' => $planId,
            'subscription_status' => 'active', 'public_api_token' => (string) Str::uuid()]);
        app(CompanyModuleService::class)->enable($this->company->id, 'stock');
        $this->car = Car::factory()->create(['company_id' => $this->company->id, 'status' => 'active']);
        $this->otherCar = Car::factory()->create(['company_id' => $this->other->id, 'status' => 'active']);

        // Tracking ativo desde agosto (o marketing do automóvel só compara com tracking).
        DB::table('car_views')->insert(['company_id' => $this->company->id, 'car_id' => $this->car->id, 'ip_address' => '127.0.0.1',
            'created_at' => '2026-08-01 09:00:00', 'updated_at' => '2026-08-01 09:00:00']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function at(string $datetime): void
    {
        Carbon::setTestNow(Carbon::parse($datetime));
        CarbonImmutable::setTestNow(CarbonImmutable::parse($datetime));
    }

    private function track(string $type, array $data = [], ?string $visitorId = null)
    {
        return $this->withHeaders(['Origin' => 'https://quebom.pt'])
            ->postJson('/api/public/track?token=' . $this->company->public_api_token, [
                'type' => $type,
                'data' => $data,
                'tracking' => ['visitor_id' => $visitorId ?? (string) Str::uuid(), 'session_id' => (string) Str::uuid()],
            ]);
    }

    private function whatsappContactsInMarketing(): int
    {
        $this->at(self::NOW);

        return app(AutomotiveMarketingService::class)->build($this->company->id)['metrics']['contacts']['by_type']['whatsapp'];
    }

    private function funnel(): object
    {
        return DB::table('car_funnel_metrics_daily')->where('company_id', $this->company->id)->where('car_id', $this->car->id)
            ->selectRaw('COALESCE(SUM(whatsapp_clicks), 0) AS whatsapp, COALESCE(SUM(contacts), 0) AS contacts')->first();
    }

    // ── a regra ───────────────────────────────────────────────────────────────

    public function test_the_rule_in_php(): void
    {
        $this->assertTrue(WhatsappContact::isContact('whatsapp_send', null));
        $this->assertTrue(WhatsappContact::isContact('whatsapp_send', 'modal'));
        $this->assertTrue(WhatsappContact::isContact('whatsapp_click', null));
        $this->assertTrue(WhatsappContact::isContact('whatsapp_click', 'vehicle_gallery'));
        $this->assertFalse(WhatsappContact::isContact('whatsapp_click', 'floating_button'));
        $this->assertFalse(WhatsappContact::isContact('call_click', null));
    }

    // ── whatsapp_send ─────────────────────────────────────────────────────────

    public function test_whatsapp_send_is_accepted_and_counted(): void
    {
        $visitor = (string) Str::uuid();
        $this->track('whatsapp_send', ['car_id' => $this->car->id, 'page_context' => 'modal'], $visitor)
            ->assertStatus(201)->assertJsonPath('type', 'whatsapp_send');

        $this->assertDatabaseHas('car_interactions', ['company_id' => $this->company->id, 'car_id' => $this->car->id,
            'interaction_type' => 'whatsapp_send', 'page_context' => 'modal']);
        $this->assertSame(1, (int) $this->funnel()->whatsapp);
        $this->assertSame(1, (int) $this->funnel()->contacts);
        $this->assertSame(1, app(CarInteractionRepository::class)->countWhatsappContacts($this->car->id));
        $this->assertTrue((bool) CarAdAttribution::where('car_id', $this->car->id)->where('visitor_id', $visitor)->value('has_whatsapp_click'));
        $this->assertSame(1, $this->whatsappContactsInMarketing());
    }

    // ── botão flutuante: gravado, não contado ──────────────────────────────────

    public function test_floating_button_is_saved_but_not_counted(): void
    {
        $visitor = (string) Str::uuid();
        // Sem viatura (o botão geral) e com a viatura inferida pelo xplendor.js: nenhum conta.
        $this->track('whatsapp_click', ['page_context' => 'floating_button'])->assertStatus(201);
        $this->track('whatsapp_click', ['car_id' => $this->car->id, 'page_context' => 'floating_button',
            'meta' => ['car_id_source' => 'last_car_view']], $visitor)->assertStatus(201);

        $this->assertSame(2, CarInteraction::where('company_id', $this->company->id)->where('page_context', 'floating_button')->count());
        $this->assertSame(0, (int) $this->funnel()->whatsapp);
        $this->assertSame(0, (int) $this->funnel()->contacts);
        $this->assertSame(0, app(CarInteractionRepository::class)->countWhatsappContacts($this->car->id));
        $this->assertFalse((bool) CarAdAttribution::where('car_id', $this->car->id)->where('visitor_id', $visitor)->value('has_whatsapp_click'));
        $this->assertSame(0, $this->whatsappContactsInMarketing());
    }

    public function test_page_context_from_meta_is_used_when_data_has_none(): void
    {
        $this->track('whatsapp_click', ['meta' => ['page_context' => 'floating_button']])->assertStatus(201);

        $this->assertDatabaseHas('car_interactions', ['company_id' => $this->company->id, 'page_context' => 'floating_button']);
        $this->assertSame(0, $this->whatsappContactsInMarketing());
    }

    // ── os outros sites (sem page_context) contam como hoje ──────────────────

    public function test_other_sites_without_page_context_count_as_before(): void
    {
        $visitor = (string) Str::uuid();
        $this->track('whatsapp_click', ['car_id' => $this->car->id], $visitor)->assertStatus(201);

        $this->assertSame(1, (int) $this->funnel()->whatsapp);
        $this->assertSame(1, (int) $this->funnel()->contacts);
        $this->assertSame(1, app(CarInteractionRepository::class)->countWhatsappContacts($this->car->id));
        $this->assertTrue((bool) CarAdAttribution::where('car_id', $this->car->id)->where('visitor_id', $visitor)->value('has_whatsapp_click'));
        $this->assertSame(1, $this->whatsappContactsInMarketing());
    }

    // ── viatura de outra empresa ──────────────────────────────────────────────

    public function test_interaction_with_another_companys_car_is_saved_without_car(): void
    {
        $this->track('whatsapp_click', ['car_id' => $this->otherCar->id, 'page_context' => 'vehicle_gallery'])
            ->assertStatus(201);

        $row = CarInteraction::where('company_id', $this->company->id)->sole();
        $this->assertNull($row->car_id);
        $this->assertTrue((bool) ($row->meta['car_id_unresolved'] ?? false));
        $this->assertSame(0, CarInteraction::where('car_id', $this->otherCar->id)->count());
        $this->assertSame(0, CarAdAttribution::where('car_id', $this->otherCar->id)->count());
        $this->assertSame(1, $this->whatsappContactsInMarketing()); // conta como WhatsApp da empresa
    }

    public function test_unknown_car_still_404_on_car_view_and_car_lead(): void
    {
        $this->track('car_view', ['car_id' => $this->otherCar->id])->assertStatus(404);
        $this->track('car_lead', ['car_id' => $this->otherCar->id, 'name' => 'Ana', 'email' => 'ana@exemplo.pt'])->assertStatus(404);

        $this->assertSame(0, CarLead::count());
        $this->assertSame(0, DB::table('car_views')->where('car_id', $this->otherCar->id)->count());
    }

    // ── deduplicação ──────────────────────────────────────────────────────────

    public function test_duplicate_only_within_5s_and_with_the_same_page_context(): void
    {
        $v = (string) Str::uuid();
        $gallery = ['car_id' => $this->car->id, 'page_context' => 'vehicle_gallery'];

        $this->track('whatsapp_click', $gallery, $v)->assertStatus(201);
        $this->track('whatsapp_click', $gallery, $v)->assertStatus(200)->assertJsonPath('deduped', true);
        // Outro botão (outro page_context) no mesmo segundo: não se funde.
        $this->track('whatsapp_click', ['car_id' => $this->car->id, 'page_context' => 'vehicle_mobile_cta'], $v)->assertStatus(201);
        // O mesmo botão 6 s depois: é outro clique.
        $this->at('2026-10-05 11:00:06');
        $this->track('whatsapp_click', $gallery, $v)->assertStatus(201);

        $this->assertSame(3, CarInteraction::where('car_id', $this->car->id)->where('interaction_type', 'whatsapp_click')->count());
    }

    // ── dashboards da empresa ─────────────────────────────────────────────────

    public function test_company_dashboards_count_clicks_without_car(): void
    {
        $this->track('whatsapp_click', [])->assertStatus(201);                                    // outro site, sem viatura
        $this->track('whatsapp_send', ['car_id' => null, 'page_context' => 'modal'])->assertStatus(201);
        $this->track('whatsapp_click', ['page_context' => 'floating_button'])->assertStatus(201); // só abriu o modal

        $this->at('2026-10-06 12:00:00');
        $perf = app(DashboardRepository::class)->getMarketingPerformance($this->company->id);
        $this->assertSame(2, $perf['interactions_last_7_days']); // a abertura do modal não conta duas vezes

        $this->assertSame(2, $this->whatsappContactsInMarketing());
    }

    // ── a vista do funil: down() volta à definição anterior ──────────────────

    public function test_funnel_view_migration_down_restores_the_previous_count(): void
    {
        $this->track('whatsapp_send', ['car_id' => $this->car->id, 'page_context' => 'modal'])->assertStatus(201);
        $migration = require database_path('migrations/2027_01_07_100000_whatsapp_contact_rule_in_car_funnel_view.php');

        $migration->down();
        $this->assertSame(0, (int) $this->funnel()->whatsapp); // antes da regra, só o whatsapp_click contava

        $migration->up();
        $this->assertSame(1, (int) $this->funnel()->whatsapp);
    }

    // ── observabilidade ───────────────────────────────────────────────────────

    public function test_refusals_are_logged_without_token_or_personal_data(): void
    {
        Log::spy();

        $this->track('whatsapp_unknown', ['email' => 'ana@exemplo.pt'])->assertStatus(422);
        $this->track('car_view', ['car_id' => $this->otherCar->id])->assertStatus(404);

        $token = $this->company->public_api_token;
        Log::shouldHaveReceived('info')->with('[Track] pedido recusado', Mockery::on(fn ($c) => $c['company_id'] === $this->company->id
            && $c['type'] === 'whatsapp_unknown' && $c['status'] === 422 && str_contains($c['motivo'], 'type')
            && $c['origem'] === 'quebom.pt' && ! str_contains(json_encode($c), $token) && ! str_contains(json_encode($c), 'ana@')))->once();
        Log::shouldHaveReceived('info')->with('[Track] pedido recusado', Mockery::on(fn ($c) => $c['type'] === 'car_view'
            && $c['status'] === 404 && $c['origem'] === 'quebom.pt'))->once();
    }
}
