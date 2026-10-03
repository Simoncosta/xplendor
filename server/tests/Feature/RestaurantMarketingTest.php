<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\MetaAccountInsightDaily;
use App\Models\PingwinDailySale;
use App\Models\PingwinLocation;
use App\Models\PingwinSyncRun;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\Ga4\Ga4ClientInterface;
use App\Services\GoogleAnalyticsService;
use App\Services\RestaurantMarketingService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XPLENDOR — Dashboard de restauração: bloco "marketing e resultados" (Parte A).
 * Cobre: GA4 com datas explícitas (+ não-regressão das janelas), os escalões de
 * comparação (ano anterior / mês anterior com aviso de sazonalidade / sem histórico;
 * mesmos dias no mês em curso; mês passado inteiro), os valores das 5 métricas, os
 * estados (GA4/Meta não configurados, token expirado, conta em falta, erro GA4) e a
 * tenancy (A pede B → 403).
 */
class RestaurantMarketingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;
    private PingwinLocation $loc;
    private MarketingFakeGa4 $ga4;
    private int $planId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-16 12:00:00'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-16 12:00:00'));

        $this->planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = $this->makeCompany('500004001', 'Yuko');
        $this->user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
        $this->loc = PingwinLocation::create(['company_id' => $this->company->id, 'winrest_store_id' => 'A', 'display_name' => 'Baixa', 'is_active' => true]);

        $this->ga4 = new MarketingFakeGa4();
        $this->app->instance(Ga4ClientInterface::class, $this->ga4);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function makeCompany(string $nipc, string $name): Company
    {
        $c = Company::create(['nipc' => $nipc, 'fiscal_name' => $name, 'plan_id' => $this->planId, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->enable($c->id, 'pingwin');

        return $c;
    }

    private function sale(string $date, int $cents): void
    {
        PingwinDailySale::create(['company_id' => $this->company->id, 'location_id' => $this->loc->id, 'business_date' => $date, 'invoiced_cents' => $cents, 'net_cents' => $cents, 'synced_at' => now()]);
        $this->synced($date, $date);
    }

    /** Marca como sincronizados todos os dias [from, to] (sem vendas = 0 real). */
    private function synced(string $from, string $to): void
    {
        for ($d = CarbonImmutable::parse($from); $d->lte(CarbonImmutable::parse($to)); $d = $d->addDay()) {
            PingwinSyncRun::firstOrCreate(['company_id' => $this->company->id, 'business_date' => $d->toDateString()], ['status' => 'success', 'synced_at' => now()]);
        }
    }

    private function cm(string $date, int $guests, int $reservations, int $walkIns): void
    {
        DB::table('cm_reservation_shift_summary')->insert([
            'company_id' => $this->company->id, 'location_id' => $this->loc->id, 'business_date' => $date,
            'shift' => 'dinner', 'guests_total' => $guests, 'reservations_count' => $reservations,
            'walk_ins_count' => $walkIns, 'cancelled_count' => 0, 'synced_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function meta(array $overrides = []): CompanyIntegration
    {
        return CompanyIntegration::create(array_merge([
            'company_id' => $this->company->id, 'platform' => 'meta', 'access_token' => 'tok',
            'account_id' => '123', 'token_expires_at' => now()->addDays(30), 'status' => 'active',
            'insights_backfilled_at' => now(), 'insights_backfill_months' => 13,
            'insights_sync_status' => 'done', 'insights_synced_until' => '2026-10-16',
        ], $overrides));
    }

    private function metaRow(string $date, float $spend, int $clicks): void
    {
        MetaAccountInsightDaily::create(['company_id' => $this->company->id, 'account_id' => '123', 'date' => $date,
            'campaign_id' => 'c1', 'campaign_name' => 'Outono', 'spend' => $spend, 'impressions' => $clicks * 20, 'clicks' => $clicks]);
    }

    private function ga4Integration(): void
    {
        CompanyIntegration::create(['company_id' => $this->company->id, 'platform' => 'google', 'access_token' => '', 'property_id' => '398765432', 'status' => 'active']);
    }

    private function build(?string $month = null): array
    {
        return app(RestaurantMarketingService::class)->build($this->company->id, $month);
    }

    // ── GA4 com datas explícitas + não-regressão das janelas ─────────────────

    public function test_ga4_explicit_dates_are_used_and_rolling_windows_still_work(): void
    {
        $svc = app(GoogleAnalyticsService::class);

        $explicit = $svc->getTraffic($this->company->id, 398765432, 28, false, '2025-10-01', '2025-10-31');
        $this->assertSame(['start' => '2025-10-01', 'end' => '2025-10-31', 'days' => 31], $explicit['range']);
        $this->assertTrue($this->ga4->sawRange('2025-10-01', '2025-10-31'));

        // Janela de 28 dias (comportamento de sempre): acaba hoje.
        $rolling = $svc->getTraffic($this->company->id, 398765432, 28);
        $this->assertSame(['start' => '2026-09-19', 'end' => '2026-10-16', 'days' => 28], $rolling['range']);
    }

    public function test_ga4_traffic_endpoint_accepts_and_validates_dates(): void
    {
        $this->ga4Integration();
        $base = '/api/v1/companies/' . $this->company->id . '/analytics/ga4/traffic';

        $this->actingAs($this->user, 'sanctum')->getJson($base . '?start=2025-10-01&end=2025-10-31')
            ->assertStatus(200)->assertJsonPath('data.traffic.range.start', '2025-10-01')->assertJsonPath('data.traffic.range.days', 31);
        $this->actingAs($this->user, 'sanctum')->getJson($base . '?start=2025-10-31&end=2025-10-01')->assertStatus(422); // fim antes do início
        $this->actingAs($this->user, 'sanctum')->getJson($base . '?start=2025-10-01')->assertStatus(422);                 // falta o fim
        $this->actingAs($this->user, 'sanctum')->getJson($base . '?days=7')->assertStatus(200)->assertJsonPath('data.traffic.range.days', 7);
    }

    // ── Escalões de comparação ────────────────────────────────────────────────

    public function test_current_month_uses_same_days_and_previous_month_with_seasonality_when_no_last_year(): void
    {
        // Yuko: sem 2025. Setembro e outubro sincronizados; hoje 16/10 → período 1..15.
        $this->synced('2026-09-01', '2026-10-15');
        $this->sale('2026-09-05', 100000); // 1000 € — dentro de 1..15 de setembro
        $this->sale('2026-09-25', 900000); // fora dos mesmos dias → NÃO conta
        $this->sale('2026-10-05', 120000); // 1200 €

        $out = $this->build();

        $this->assertTrue($out['is_current_month']);
        $this->assertSame(['start' => '2026-10-01', 'end' => '2026-10-15', 'days' => 15], $out['period']);
        $cmp = $out['metrics']['revenue']['comparison'];
        $this->assertSame('previous_month', $cmp['tier']);
        $this->assertTrue($cmp['seasonality_warning']);
        $this->assertSame(['start' => '2026-09-01', 'end' => '2026-09-15'], $cmp['window']); // MESMOS dias
        $this->assertEquals(1000.0, $cmp['base_value']);
        $this->assertEquals(20.0, $cmp['delta_pct']); // 1200 vs 1000
    }

    public function test_same_month_last_year_when_fully_synced(): void
    {
        $this->synced('2025-10-01', '2025-10-15');
        $this->synced('2026-09-01', '2026-10-15');
        $this->sale('2025-10-03', 80000);
        $this->sale('2026-10-05', 120000);

        $cmp = $this->build()['metrics']['revenue']['comparison'];

        $this->assertSame('same_month_last_year', $cmp['tier']);
        $this->assertSame('2025-10', $cmp['reference_month']);
        $this->assertFalse($cmp['seasonality_warning']);
        $this->assertSame(['start' => '2025-10-01', 'end' => '2025-10-15'], $cmp['window']);
        $this->assertEquals(50.0, $cmp['delta_pct']); // 1200 vs 800
    }

    public function test_past_month_compares_full_month_against_full_previous_month(): void
    {
        $this->synced('2026-08-01', '2026-09-30');
        $this->sale('2026-08-31', 50000);
        $this->sale('2026-09-30', 75000);

        $out = $this->build('2026-09');

        $this->assertFalse($out['is_current_month']);
        $this->assertSame(['start' => '2026-09-01', 'end' => '2026-09-30', 'days' => 30], $out['period']);
        $this->assertSame(['start' => '2026-08-01', 'end' => '2026-08-31'], $out['metrics']['revenue']['comparison']['window']);
        $this->assertEquals(50.0, $out['metrics']['revenue']['comparison']['delta_pct']);
    }

    public function test_incomplete_history_gives_no_comparison(): void
    {
        $this->synced('2026-09-03', '2026-10-15'); // faltam 1 e 2 de setembro
        $this->sale('2026-10-05', 120000);

        $this->assertSame(['tier' => null, 'reason' => 'no_history'], $this->build()['metrics']['revenue']['comparison']);
    }

    public function test_first_day_of_month_has_no_complete_days(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-01 09:00:00'));
        Carbon::setTestNow(Carbon::parse('2026-10-01 09:00:00'));

        $out = $this->build();

        $this->assertSame(0, $out['period']['days']);
        $this->assertNull($out['metrics']['revenue']);
    }

    // ── Valores das 5 métricas ───────────────────────────────────────────────

    public function test_the_five_metrics(): void
    {
        $this->synced('2026-09-01', '2026-10-15');
        $this->sale('2026-10-05', 200000);           // 2000 €
        $this->cm('2026-10-05', 80, 30, 10);         // 80 pessoas; 30 registos dos quais 10 walk-ins
        $this->meta();
        $this->metaRow('2026-10-05', 100.0, 40);     // 100 € / 40 cliques
        $this->ga4Integration();
        $this->ga4->channels['2026-10-01'] = [
            ['20261005', 'Paid Social', 30], ['20261005', 'Organic Social', 20],
            ['20261005', 'Organic Search', 10], ['20261005', 'Direct', 15], ['20261005', 'Referral', 5],
        ];

        $m = $this->build()['metrics'];

        $this->assertEquals(2000.0, $m['revenue']['total']);
        $this->assertSame(80, $m['covers']['total']);
        $this->assertEquals(100.0, $m['meta_spend']['total']);
        $this->assertSame(40, $m['meta_clicks']['total']);
        $this->assertEquals(5.0, $m['marketing_weight']['value']);            // 100 / 2000
        $this->assertSame(20, $m['reservations_mix']['reserved']);           // 30 − 10 walk-ins
        $this->assertSame(10, $m['reservations_mix']['walk_ins']);
        $this->assertEquals(66.7, $m['reservations_mix']['reserved_share_pct']);
        $this->assertSame(80, $m['ga4_sessions']['total']);
        $this->assertSame(30, $m['ga4_sessions']['by_group']['paid']['total']);
        $this->assertSame(20, $m['ga4_sessions']['by_group']['organic_social']['total']);
        $this->assertSame(10, $m['ga4_sessions']['by_group']['search']['total']);
        $this->assertSame(15, $m['ga4_sessions']['by_group']['direct']['total']);
        $this->assertSame(5, $m['ga4_sessions']['by_group']['other']['total']);
        $this->assertCount(15, $m['revenue']['series']);                      // série diária contínua
    }

    public function test_marketing_weight_uses_a_tier_common_to_both_sources(): void
    {
        // A Meta tem 13 meses (cobre outubro de 2025) mas os internos NÃO têm 2025:
        // o gasto compara com o ano anterior, a faturação com o mês anterior, e o
        // PESO (que junta as duas) só pode usar o escalão comum → mês anterior.
        $this->synced('2026-09-01', '2026-10-15');
        $this->sale('2026-09-05', 100000);
        $this->sale('2026-10-05', 200000);
        $this->meta();
        $this->metaRow('2025-10-05', 40.0, 4);
        $this->metaRow('2026-09-05', 50.0, 5);
        $this->metaRow('2026-10-05', 100.0, 10);

        $m = $this->build()['metrics'];

        $this->assertSame('same_month_last_year', $m['meta_spend']['comparison']['tier']);
        $this->assertSame('previous_month', $m['revenue']['comparison']['tier']);
        $this->assertSame('previous_month', $m['marketing_weight']['comparison']['tier']);
        $this->assertEquals(5.0, $m['marketing_weight']['value']);                     // 100 / 2000
        $this->assertEquals(5.0, $m['marketing_weight']['comparison']['base_value']);  // 50 / 1000
        $this->assertEquals(0.0, $m['marketing_weight']['comparison']['delta_pp']);
    }

    public function test_meta_without_coverage_of_the_window_has_no_comparison(): void
    {
        // Backfill antigo de 90 dias concluído hoje → cobre só desde 19/07: serve o
        // mês anterior, mas NÃO o ano anterior (não inventa um "0 €" de 2025).
        $this->meta(['insights_backfill_months' => null]);
        $this->metaRow('2026-10-05', 100.0, 10);

        $cmp = $this->build()['metrics']['meta_spend']['comparison'];

        $this->assertSame('previous_month', $cmp['tier']);
        $this->assertTrue($cmp['seasonality_warning']);
    }

    // ── Estados ───────────────────────────────────────────────────────────────

    public function test_internal_only_when_meta_and_ga4_not_configured(): void
    {
        $this->synced('2026-10-01', '2026-10-15');
        $this->sale('2026-10-05', 100000);

        $out = $this->build();

        $this->assertSame('ok', $out['sources']['internal']['state']);
        $this->assertSame('not_connected', $out['sources']['meta']['state']);
        $this->assertSame('not_connected', $out['sources']['ga4']['state']);
        $this->assertNull($out['metrics']['meta_spend']);
        $this->assertNull($out['metrics']['ga4_sessions']);
        $this->assertNotNull($out['metrics']['revenue']);
    }

    public function test_meta_token_expired_keeps_ingested_data_and_flags_state(): void
    {
        $this->meta(['status' => 'expired']);
        $this->metaRow('2026-10-05', 70.0, 7);

        $out = $this->build();

        $this->assertSame('token_expired', $out['sources']['meta']['state']);
        $this->assertEquals(70.0, $out['metrics']['meta_spend']['total']);
    }

    public function test_meta_needs_account_state(): void
    {
        $this->meta(['account_id' => null]);

        $out = $this->build();

        $this->assertSame('needs_account', $out['sources']['meta']['state']);
        $this->assertNull($out['metrics']['meta_spend']);
    }

    public function test_ga4_error_is_a_state_not_a_crash(): void
    {
        $this->ga4Integration();
        $this->ga4->throw = true;

        $out = $this->build();

        $this->assertSame('error', $out['sources']['ga4']['state']);
        $this->assertNull($out['metrics']['ga4_sessions']);
    }

    // ── Tenancy + validação ──────────────────────────────────────────────────

    public function test_tenancy_company_a_cannot_read_b(): void
    {
        $other = $this->makeCompany('500004002', 'Outro restaurante');
        $url = fn (int $id) => "/api/v1/companies/{$id}/analytics/restaurant/marketing";

        $this->actingAs($this->user, 'sanctum')->getJson($url($other->id))->assertStatus(403);
        $this->actingAs($this->user, 'sanctum')->getJson($url($this->company->id))->assertStatus(200)
            ->assertJsonPath('data.month', '2026-10');
    }

    public function test_month_validation(): void
    {
        $url = '/api/v1/companies/' . $this->company->id . '/analytics/restaurant/marketing';

        $this->actingAs($this->user, 'sanctum')->getJson($url . '?month=2026-13')->assertStatus(422);
        $this->actingAs($this->user, 'sanctum')->getJson($url . '?month=2026-11')->assertStatus(422); // futuro
        $this->actingAs($this->user, 'sanctum')->getJson($url . '?month=2026-09')->assertStatus(200)->assertJsonPath('data.month', '2026-09');
    }
}

/** GA4 falso: regista os intervalos pedidos e devolve linhas por canal/dia por início de janela. */
class MarketingFakeGa4 implements Ga4ClientInterface
{
    /** @var array<string, array<int, array{0:string,1:string,2:int}>> start => [[yyyymmdd, canal, sessões]] */
    public array $channels = [];
    public array $specs = [];
    public bool $throw = false;

    public function runReport(int $propertyId, array $spec): array
    {
        if ($this->throw) {
            throw new \RuntimeException('GA4 indisponível');
        }
        $this->specs[] = $spec;

        if (($spec['dimensions'] ?? []) === ['date', 'sessionDefaultChannelGroup']) {
            $rows = array_map(fn ($r) => ['dimensions' => [$r[0], $r[1]], 'metrics' => [(string) $r[2]]], $this->channels[$spec['start']] ?? []);

            return ['rows' => $rows, 'subjectToThresholding' => false];
        }

        return ['rows' => [], 'subjectToThresholding' => false];
    }

    public function sawRange(string $start, string $end): bool
    {
        foreach ($this->specs as $s) {
            if (($s['start'] ?? null) === $start && ($s['end'] ?? null) === $end) {
                return true;
            }
        }

        return false;
    }
}
