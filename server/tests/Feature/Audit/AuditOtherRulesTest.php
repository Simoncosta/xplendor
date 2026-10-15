<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Audit\AuditFindingData;
use App\Audit\AuditRunService;
use App\Audit\AuditScope;
use App\Audit\Rules\Automotive\AutocaravanaSemTipologiaRule;
use App\Audit\Rules\Tracking\Ga4DivergenciaRule;
use App\Audit\Rules\Tracking\PedidosRecusadosRule;
use App\Models\AuditFinding;
use App\Models\Car;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\TrackingDailyCount;
use App\Models\TrackingEventMap;
use App\Services\CompanyModuleService;
use App\Services\Ga4\Ga4ClientInterface;
use App\Services\Tracking\TrackingHealthService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** O GA4 de teste: devolve as linhas que o teste lhe der (date, eventName, hostName → eventCount). */
final class FakeGa4 implements Ga4ClientInterface
{
    public array $rows = [];
    public array $specs = [];

    public function runReport(int $propertyId, array $spec): array
    {
        $this->specs[] = ['property' => $propertyId] + $spec;

        return ['rows' => $this->rows, 'subjectToThresholding' => false];
    }
}

/**
 * Fatia 2, Parte B — as regras fora da restauração, com o mesmo motor: a saúde do tracking dos sites
 * (GA4 × XPLENDOR, incluindo o WhatsApp da Quebom antes da correção, e os pedidos recusados pelo
 * /api/public/track) e as autocaravanas sem categoria ou tipologia.
 */
class AuditOtherRulesTest extends TestCase
{
    use RefreshDatabase;

    private Company $quebom;
    private FakeGa4 $ga4;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-15 09:30:00', 'Europe/Lisbon'));
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->quebom = Company::create(['nipc' => '500000901', 'fiscal_name' => 'Quebom', 'plan_id' => $planId, 'website' => 'https://quebom.pt',
            'subscription_status' => 'active', 'public_api_token' => (string) Str::uuid()]);
        foreach (['stock', 'marketing_analytics'] as $m) {
            app(CompanyModuleService::class)->enable($this->quebom->id, $m);
        }
        CompanyIntegration::create(['company_id' => $this->quebom->id, 'platform' => 'google', 'status' => 'active', 'access_token' => '', 'property_id' => '544947543']);
        $this->ga4 = new FakeGa4();
        $this->app->instance(Ga4ClientInterface::class, $this->ga4);
    }

    /** @return list<AuditFindingData> */
    private function findings(string $ruleClass, ?int $companyId = null): array
    {
        $c = $companyId ?? $this->quebom->id;

        return iterator_to_array(app($ruleClass)->run($c, new AuditScope($c, 'tracking', 'diaria')), false);
    }

    private function interactions(string $type, string $day, int $n, ?string $context = null): void
    {
        for ($i = 0; $i < $n; $i++) {
            DB::table('car_interactions')->insert([
                'company_id' => $this->quebom->id, 'interaction_type' => $type, 'page_context' => $context,
                'visitor_id' => (string) Str::uuid(), 'session_id' => (string) Str::uuid(),
                'created_at' => "{$day} 12:00:00", 'updated_at' => "{$day} 12:00:00",
            ]);
        }
    }

    /** GA4: o mesmo nº de eventos por dia, num intervalo de dias. */
    private function ga4Days(string $event, string $from, int $days, int $count, string $host = 'www.quebom.pt'): void
    {
        for ($d = 0; $d < $days; $d++) {
            $date = CarbonImmutable::parse($from)->addDays($d)->format('Ymd');
            $this->ga4->rows[] = ['dimensions' => [$date, $event, $host], 'metrics' => [(string) $count]];
        }
    }

    // ── GA4 × XPLENDOR ───────────────────────────────────────────────────────

    public function test_quebom_whatsapp_before_the_fix_is_caught(): void
    {
        // O site conta "whatsapp_click" no GA4; na XPLENDOR, só os contactos (sem o botão flutuante, que só abre o modal).
        TrackingEventMap::create(['company_id' => $this->quebom->id, 'ga4_event' => 'whatsapp_click', 'xplendor_type' => 'whatsapp_click',
            'filters' => ['exclude_page_context' => ['floating_button']]]);
        $this->ga4Days('whatsapp_click', '2026-10-01', 14, 8);
        for ($d = 1; $d <= 14; $d++) {
            $this->interactions('whatsapp_click', sprintf('2026-10-%02d', $d), 10, 'floating_button');   // antes da correção: só o botão que abre
        }

        $sync = app(TrackingHealthService::class)->sync($this->quebom->id);
        $this->assertTrue($sync['ga4']);
        $this->assertSame(['date', 'eventName', 'hostName'], $this->ga4->specs[0]['dimensions']);
        $this->assertSame(['eventCount'], $this->ga4->specs[0]['metrics']);
        $this->assertSame(8, TrackingDailyCount::where('source', 'ga4')->where('event_type', 'whatsapp_click')->where('day', '2026-10-10')->value('count'));
        $this->assertSame(0, TrackingDailyCount::where('source', 'xplendor')->where('event_type', 'whatsapp_click')->where('day', '2026-10-10')->value('count'));

        $f = $this->findings(Ga4DivergenciaRule::class);
        $this->assertCount(1, $f);
        $this->assertSame('alta', $f[0]->severity);
        $this->assertSame('whatsapp_click', $f[0]->entityId);
        $this->assertStringContainsString('Durante 14 dias seguidos', $f[0]->seen);
        $this->assertStringContainsString('contou 112 cliques no WhatsApp e a XPLENDOR contou 0.', $f[0]->seen);
        $this->assertSame('Cliques no WhatsApp: o Google Analytics conta, a XPLENDOR não', $f[0]->title);

        // Depois da correção: os contactos chegam (com o page_context do sítio do clique) e o achado resolve-se.
        for ($d = 1; $d <= 14; $d++) {
            $this->interactions('whatsapp_click', sprintf('2026-10-%02d', $d), 8, 'seller_contact_box');
        }
        app(TrackingHealthService::class)->sync($this->quebom->id);
        $this->assertSame([], $this->findings(Ga4DivergenciaRule::class));
    }

    public function test_divergence_below_70_percent_for_three_days(): void
    {
        $this->ga4Days('call_click', '2026-10-10', 5, 10);
        foreach (['2026-10-10' => 10, '2026-10-11' => 4, '2026-10-12' => 5, '2026-10-13' => 6, '2026-10-14' => 10] as $day => $n) {
            $this->interactions('call_click', $day, $n);
        }
        app(TrackingHealthService::class)->sync($this->quebom->id);
        $f = $this->findings(Ga4DivergenciaRule::class);
        $this->assertCount(1, $f);
        $this->assertSame(['media', 'diverge'], [$f[0]->severity, $f[0]->evidenceKey]);
        $this->assertStringContainsString('Durante 3 dias seguidos (11/10/2026 a 13/10/2026), a XPLENDOR contou 15', $f[0]->seen);

        // Dentro de 70% a 130%, ou abaixo de 5 por dia no GA4: nada.
        DB::table('car_interactions')->delete();
        foreach (['2026-10-10', '2026-10-11', '2026-10-12', '2026-10-13', '2026-10-14'] as $day) {
            $this->interactions('call_click', $day, 8);
        }
        app(TrackingHealthService::class)->sync($this->quebom->id);
        $this->assertSame([], $this->findings(Ga4DivergenciaRule::class));
    }

    public function test_a_shared_property_counts_only_the_company_site(): void
    {
        $other = Company::create(['nipc' => '500000902', 'fiscal_name' => 'Xplendor', 'plan_id' => DB::table('plans')->value('id'), 'website' => 'https://xplendor.pt',
            'subscription_status' => 'active', 'public_api_token' => (string) Str::uuid()]);
        CompanyIntegration::create(['company_id' => $other->id, 'platform' => 'google', 'status' => 'active', 'access_token' => '', 'property_id' => '544947543']);
        $this->ga4Days('whatsapp_click', '2026-10-14', 1, 8, 'www.quebom.pt');
        $this->ga4Days('whatsapp_click', '2026-10-14', 1, 100, 'xplendor.pt');
        app(TrackingHealthService::class)->sync($this->quebom->id);
        app(TrackingHealthService::class)->sync($other->id);
        $count = fn ($c) => TrackingDailyCount::where('company_id', $c)->where('source', 'ga4')->where('event_type', 'whatsapp_click')->where('day', '2026-10-14')->value('count');
        $this->assertSame(8, $count($this->quebom->id));
        $this->assertSame(100, $count($other->id));
    }

    // ── Pedidos recusados pelo /track ────────────────────────────────────────

    public function test_rejected_track_requests_are_counted_and_flagged(): void
    {
        config(['auditoria.tracking_rejections_per_day' => 20]);
        $send = fn () => $this->withHeaders(['Origin' => 'https://quebom.pt'])->postJson('/api/public/track?token=' . $this->quebom->public_api_token, [
            'type' => 'car_view', 'data' => [], 'tracking' => ['visitor_id' => (string) Str::uuid(), 'session_id' => (string) Str::uuid()],
        ]);
        for ($i = 0; $i < 5; $i++) {
            $send()->assertStatus(422);
        }
        $row = DB::table('tracking_rejections_daily')->where('company_id', $this->quebom->id)->first();
        $this->assertSame(['car_view', 422, 'data.car_id em falta', 5], [$row->event_type, (int) $row->status, $row->reason, (int) $row->count]);
        $this->assertSame('2026-10-15', substr((string) $row->day, 0, 10));
        $this->assertSame([], $this->findings(PedidosRecusadosRule::class));   // 5 ≤ 20 por dia: nada

        for ($i = 0; $i < 20; $i++) {
            $send()->assertStatus(422);
        }
        $f = $this->findings(PedidosRecusadosRule::class);
        $this->assertCount(1, $f);
        $this->assertSame('car_view|422|data.car_id em falta', $f[0]->entityId);
        $this->assertStringContainsString('a XPLENDOR recusou 25 pedidos de visitas a viaturas porque vinham sem a viatura identificada', $f[0]->seen);
        $this->assertStringContainsString('data.car_id', $f[0]->suggestion);
    }

    // ── Automóvel ────────────────────────────────────────────────────────────

    public function test_motorhome_without_category_or_typology(): void
    {
        $capucine = DB::table('car_categories')->insertGetId(['name' => 'Capucine', 'slug' => 'capucine', 'vehicle_type' => 'motorhome', 'created_at' => now(), 'updated_at' => now()]);
        $missing = Car::factory()->create(['company_id' => $this->quebom->id, 'status' => 'active', 'vehicle_type' => 'motorhome', 'car_category_id' => null]);
        Car::factory()->create(['company_id' => $this->quebom->id, 'status' => 'active', 'vehicle_type' => 'motorhome', 'car_category_id' => $capucine]);
        Car::factory()->create(['company_id' => $this->quebom->id, 'status' => 'sold', 'vehicle_type' => 'motorhome', 'car_category_id' => null]);
        Car::factory()->create(['company_id' => $this->quebom->id, 'status' => 'active', 'vehicle_type' => 'car', 'car_category_id' => null]);

        $f = iterator_to_array(app(AutocaravanaSemTipologiaRule::class)->run($this->quebom->id, new AuditScope($this->quebom->id, 'automovel', 'central')), false);
        $this->assertCount(1, $f);
        $this->assertSame((string) $missing->id, $f[0]->entityId);
        $this->assertSame("/cars/{$missing->id}", $f[0]->fixUrl);
        $this->assertStringContainsString('a XPLENDOR não a compara com o mercado', $f[0]->why);

        // Pelo motor, como as outras áreas.
        $run = app(AuditRunService::class)->start($this->quebom->id, 'automovel', 'central')->fresh();
        $this->assertSame('done', $run->status);
        $this->assertSame(1, AuditFinding::where('company_id', $this->quebom->id)->where('area', 'automovel')->count());
    }

    public function test_the_schedules(): void
    {
        $events = collect(app(Schedule::class)->events())->keyBy('description');
        $this->assertSame('30 8 * * *', $events['audit-restaurant-nightly']->expression);
        $this->assertSame('Europe/Lisbon', $events['audit-restaurant-nightly']->timezone);
        $this->assertSame('15 9 * * *', $events['tracking-health-daily']->expression);
        $this->assertContains('tracking-health-daily', config('scheduler.external_job_names'));       // chama o GA4: externa
        $this->assertNotContains('audit-restaurant-nightly', config('scheduler.external_job_names'));  // só lê o espelho: interna
    }
}
