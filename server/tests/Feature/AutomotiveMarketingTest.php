<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Car;
use App\Models\CarBrand;
use App\Models\CarModel;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\MetaAccountInsightDaily;
use App\Models\MetaAd;
use App\Models\MetaAdInsightDaily;
use App\Models\User;
use App\Services\Automotive\AutomotiveMarketingService;
use App\Services\CompanyModuleService;
use App\Services\Ga4\Ga4ClientInterface;
use App\Services\MetaAdCarAllocator;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XPLENDOR — Dashboard do automóvel: bloco "Marketing e resultados" (Parte A).
 *
 * Cobre: escalões (ano anterior / mês anterior com sazonalidade / sem histórico),
 * mesmos dias no mês em curso e mês inteiro no passado, leads totais e pagas,
 * contactos diretos, investimento Meta com a divisão por viatura / stock geral /
 * por atribuir, visitas GA4 por origem, CPL pago sem divisão por zero, vendas só
 * como contexto (sem delta), aviso "possíveis anúncios sem parâmetros UTM",
 * estados honestos (Meta, GA4, tracking) e tenancy.
 */
class AutomotiveMarketingTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-10-16 12:00:00';   // período: 01/10 a 15/10 (15 dias completos)

    private Company $company;
    private User $user;
    private AutoMarketingFakeGa4 $ga4;
    private int $planId;
    private Car $car;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::NOW));
        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::NOW));

        $this->planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = $this->makeCompany('500012001', 'Stand A');
        $this->user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);

        $brand = CarBrand::firstOrCreate(['slug' => 'vw'], ['name' => 'VW', 'vehicle_type' => 'car']);
        $model = CarModel::firstOrCreate(['name' => 'Golf', 'car_brand_id' => $brand->id]);
        $this->car = Car::factory()->create(['company_id' => $this->company->id, 'car_brand_id' => $brand->id, 'car_model_id' => $model->id, 'status' => 'active']);

        $this->ga4 = new AutoMarketingFakeGa4();
        $this->app->instance(Ga4ClientInterface::class, $this->ga4);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function makeCompany(string $nipc, string $name): Company
    {
        $c = Company::create(['nipc' => $nipc, 'fiscal_name' => $name, 'plan_id' => $this->planId, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->enable($c->id, 'stock');

        return $c;
    }

    private function lead(string $datetime, ?string $channel = null): void
    {
        DB::table('car_leads')->insert(['name' => 'Cliente', 'email' => 'c@x.pt', 'car_id' => $this->car->id, 'company_id' => $this->company->id,
            'status' => 'new', 'channel' => $channel, 'created_at' => $datetime, 'updated_at' => $datetime]);
    }

    /** Marca o início do tracking do site (1.ª vista). */
    private function trackingSince(string $date): void
    {
        DB::table('car_views')->insert(['company_id' => $this->company->id, 'car_id' => $this->car->id, 'ip_address' => '127.0.0.1',
            'created_at' => $date . ' 09:00:00', 'updated_at' => $date . ' 09:00:00']);
    }

    private function interaction(string $type, string $datetime): void
    {
        DB::table('car_interactions')->insert(['company_id' => $this->company->id, 'car_id' => $this->car->id, 'interaction_type' => $type,
            'visitor_id' => 'v' . uniqid(), 'session_id' => 's' . uniqid(), 'created_at' => $datetime, 'updated_at' => $datetime]);
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

    private function adSpend(string $adId, string $name, float $spend, string $date): void
    {
        MetaAdInsightDaily::create(['company_id' => $this->company->id, 'account_id' => '123', 'date' => $date, 'campaign_id' => 'c1',
            'adset_id' => 's1', 'ad_id' => $adId, 'ad_name' => $name, 'spend' => $spend, 'impressions' => 100, 'clicks' => 3]);
        MetaAd::updateOrCreate(['company_id' => $this->company->id, 'account_id' => '123', 'ad_id' => $adId], ['ad_name' => $name, 'campaign_id' => 'c1']);
        app(MetaAdCarAllocator::class)->rebuild($this->company->id, '123');
    }

    private function build(?string $month = null): array
    {
        return app(AutomotiveMarketingService::class)->build($this->company->id, $month);
    }

    // ── escalões e mesmos dias ────────────────────────────────────────────────

    public function test_previous_month_tier_with_seasonality_and_same_days(): void
    {
        $this->trackingSince('2026-08-20');      // sem histórico do ano anterior
        $this->lead('2026-10-03 10:00:00', 'paid');
        $this->lead('2026-10-10 10:00:00');
        $this->lead('2026-10-16 09:00:00');      // hoje: dia incompleto, não conta
        $this->lead('2026-09-05 10:00:00');      // base (dentro de 01/09 a 15/09)
        $this->lead('2026-09-20 10:00:00');      // fora dos mesmos dias

        $out = $this->build();
        $l = $out['metrics']['leads'];

        $this->assertSame(['start' => '2026-10-01', 'end' => '2026-10-15', 'days' => 15], $out['period']);
        $this->assertSame(2, $l['total']);
        $this->assertSame(1, $l['paid']);
        $this->assertSame('previous_month', $l['comparison']['tier']);
        $this->assertTrue($l['comparison']['seasonality_warning']);
        $this->assertSame(['start' => '2026-09-01', 'end' => '2026-09-15'], $l['comparison']['window']);
        $this->assertSame(1, $l['comparison']['base_value']);
        $this->assertSame(100.0, $l['comparison']['delta_pct']);
        $this->assertCount(15, $l['series']);
    }

    public function test_same_month_last_year_when_tracking_covers_it(): void
    {
        $this->trackingSince('2025-09-01');
        $this->lead('2026-10-03 10:00:00');
        $this->lead('2025-10-04 10:00:00');
        $this->lead('2025-10-05 10:00:00');

        $c = $this->build()['metrics']['leads']['comparison'];

        $this->assertSame('same_month_last_year', $c['tier']);
        $this->assertFalse($c['seasonality_warning']);
        $this->assertSame(2, $c['base_value']);
        $this->assertSame(-50.0, $c['delta_pct']);
    }

    public function test_no_history_when_tracking_started_this_month(): void
    {
        $this->trackingSince('2026-10-02');
        $this->lead('2026-10-03 10:00:00');

        $out = $this->build();
        $this->assertSame(['tier' => null, 'reason' => 'tracking_started', 'tracking_since' => '2026-10-02'], $out['metrics']['leads']['comparison']);
        $this->assertSame('tracking_started', $out['metrics']['contacts']['comparison']['reason']);
    }

    public function test_past_month_compares_full_month_against_full_previous_month(): void
    {
        $this->trackingSince('2026-07-01');
        $this->lead('2026-09-28 10:00:00');
        $this->lead('2026-08-31 10:00:00');

        $out = $this->build('2026-09');

        $this->assertSame(['start' => '2026-09-01', 'end' => '2026-09-30', 'days' => 30], $out['period']);
        $this->assertSame(['start' => '2026-08-01', 'end' => '2026-08-31'], $out['metrics']['leads']['comparison']['window']);
        $this->assertSame(1, $out['metrics']['leads']['comparison']['base_value']);
    }

    public function test_first_day_of_month_has_no_complete_days(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 10:00:00'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-01 10:00:00'));

        $out = $this->build();

        $this->assertSame(0, $out['period']['days']);
        $this->assertNull($out['metrics']['leads']);
        $this->assertNull($out['metrics']['sales']);
    }

    // ── métricas ──────────────────────────────────────────────────────────────

    public function test_direct_contacts_count_the_four_types_only(): void
    {
        $this->trackingSince('2026-08-01');
        foreach (['whatsapp_click', 'call_click', 'show_phone', 'copy_phone', 'form_open', 'scroll'] as $t) {
            $this->interaction($t, '2026-10-05 11:00:00');
        }
        $this->interaction('whatsapp_click', '2026-09-05 11:00:00');

        $c = $this->build()['metrics']['contacts'];

        $this->assertSame(4, $c['total']);
        $this->assertSame(['whatsapp' => 1, 'call' => 1, 'phone_reveal' => 2], $c['by_type']);
        $this->assertSame(1, $c['comparison']['base_value']);
    }

    public function test_meta_spend_clicks_and_split_by_car_general_and_unattributed(): void
    {
        $this->trackingSince('2026-08-01');
        $this->meta();
        $this->metaRow('2026-10-02', 50.0, 10);
        $this->metaRow('2026-10-03', 21.0, 4);
        $this->metaRow('2026-09-02', 40.0, 8);
        $this->adSpend('A1', "Golf [id:{$this->car->id}]", 40.0, '2026-10-02');
        $this->adSpend('A2', 'Stock geral', 25.0, '2026-10-03');
        $this->adSpend('A3', 'Golf [id:999999]', 6.0, '2026-10-03');

        $m = $this->build()['metrics']['meta_spend'];

        $this->assertSame(71.0, $m['total']);
        $this->assertSame(14, $m['clicks']);
        // O backfill de 13 meses cobre o mês do ano anterior: base real de 0 €, sem % inventada.
        $this->assertSame('same_month_last_year', $m['comparison']['tier']);
        $this->assertSame(0.0, $m['comparison']['base_value']);
        $this->assertNull($m['comparison']['delta_pct']);
        $this->assertSame(['ad_level_available' => true, 'by_car' => 40.0, 'by_car_tag' => 40.0, 'by_car_manual_mapping' => 0.0,
            'general_stock' => 25.0, 'unattributed' => 6.0], $m['breakdown']);
        $this->assertArrayNotHasKey('comparison', $m['breakdown']);
    }

    public function test_ga4_visits_by_origin_with_comparison(): void
    {
        $this->trackingSince('2026-08-01');
        CompanyIntegration::create(['company_id' => $this->company->id, 'platform' => 'google', 'access_token' => '', 'property_id' => '398765432', 'status' => 'active']);
        $this->ga4->channels['2026-10-01'] = [['20261002', 'Paid Social', 30], ['20261003', 'Organic Search', 20], ['20261003', 'Direct', 10]];
        $this->ga4->channels['2025-10-01'] = [];   // sem histórico do ano anterior
        $this->ga4->channels['2026-09-01'] = [['20260902', 'Paid Social', 15]];

        $out = $this->build();
        $g = $out['metrics']['ga4_sessions'];

        $this->assertSame('ok', $out['sources']['ga4']['state']);
        $this->assertSame(60, $g['total']);
        $this->assertSame(30, $g['by_group']['paid']['total']);
        $this->assertSame(100.0, $g['by_group']['paid']['comparison']['delta_pct']);
        $this->assertSame('previous_month', $g['comparison']['tier']);
    }

    // ── CPL pago: nunca divide por zero ───────────────────────────────────────

    public function test_paid_cpl_value_and_states_never_divide_by_zero(): void
    {
        $this->assertSame(['value' => 30.0, 'state' => 'ok'], AutomotiveMarketingService::cpl(60.0, 2));
        $this->assertSame(['value' => null, 'state' => 'spend_without_lead'], AutomotiveMarketingService::cpl(50.0, 0));
        $this->assertSame(['value' => null, 'state' => 'no_spend'], AutomotiveMarketingService::cpl(0.0, 3));
        $this->assertSame(['value' => null, 'state' => 'no_spend'], AutomotiveMarketingService::cpl(null, 0));

        $this->trackingSince('2026-08-01');
        $this->meta();
        $this->metaRow('2026-10-02', 50.0, 10);
        $cpl = $this->build()['metrics']['paid_cpl'];
        $this->assertNull($cpl['value']);
        $this->assertSame('spend_without_lead', $cpl['state']);
        $this->assertSame(['tier' => null, 'reason' => 'no_history'], $cpl['comparison']);

        $this->lead('2026-10-04 10:00:00', 'paid');
        $this->lead('2026-10-05 10:00:00', 'paid');
        $this->metaRow('2026-09-02', 30.0, 5);
        $this->lead('2026-09-03 10:00:00', 'paid');
        $cpl = $this->build()['metrics']['paid_cpl'];
        $this->assertSame(25.0, $cpl['value']);
        $this->assertSame('ok', $cpl['state']);
        $this->assertSame(30.0, $cpl['comparison']['base_value']);
    }

    // ── vendas: só contexto ───────────────────────────────────────────────────

    public function test_sales_are_context_only_without_delta_or_comparison(): void
    {
        $this->trackingSince('2026-08-01');
        foreach ([['2026-10-05 12:00:00', 18000], ['2026-10-09 12:00:00', null], ['2026-09-05 12:00:00', 9000]] as [$at, $price]) {
            $sold = Car::factory()->create(['company_id' => $this->company->id, 'car_brand_id' => $this->car->car_brand_id, 'car_model_id' => $this->car->car_model_id, 'status' => 'sold']);
            DB::table('car_sales')->insert(['car_id' => $sold->id, 'company_id' => $this->company->id, 'sale_price' => $price,
                'buyer_gender' => 'male', 'buyer_age_range' => '31-45', 'sale_channel' => 'walk_in', 'sold_at' => $at,
                'created_at' => now(), 'updated_at' => now()]);
        }

        $s = $this->build()['metrics']['sales'];

        $this->assertSame(['context_only' => true, 'count' => 2, 'revenue' => 18000.0, 'without_value' => 1], $s);
        $this->assertArrayNotHasKey('comparison', $s);
    }

    // ── aviso de qualidade: possíveis anúncios sem UTM ───────────────────────

    public function test_missing_utm_signal_when_spend_without_paid_leads_and_organic_social_leads(): void
    {
        $this->trackingSince('2026-08-01');
        $this->meta();
        $this->metaRow('2026-10-02', 80.0, 20);
        $this->lead('2026-10-04 10:00:00', 'organic_social');
        $this->lead('2026-10-05 10:00:00', 'organic_social');

        $this->assertSame([['code' => 'possible_missing_utm', 'meta_spend' => 80.0, 'paid_leads' => 0, 'organic_social_leads' => 2]],
            $this->build()['quality_signals']);

        // Com uma lead paga, o sinal desaparece.
        $this->lead('2026-10-06 10:00:00', 'paid');
        $this->assertSame([], $this->build()['quality_signals']);
    }

    public function test_no_utm_signal_without_organic_social_leads_or_without_spend(): void
    {
        $this->trackingSince('2026-08-01');
        $this->lead('2026-10-04 10:00:00', 'organic_social');
        $this->assertSame([], $this->build()['quality_signals']);   // sem Meta

        $this->meta();
        $this->metaRow('2026-10-02', 80.0, 20);
        DB::table('car_leads')->delete();
        $this->assertSame([], $this->build()['quality_signals']);   // sem leads orgânicas
    }

    // ── estados honestos ──────────────────────────────────────────────────────

    public function test_states_when_nothing_is_connected(): void
    {
        $out = $this->build();

        $this->assertSame('not_connected', $out['sources']['meta']['state']);
        $this->assertSame('not_connected', $out['sources']['ga4']['state']);
        $this->assertSame('no_data', $out['sources']['tracking']['state']);
        $this->assertNull($out['metrics']['meta_spend']);
        $this->assertNull($out['metrics']['paid_cpl']);
        $this->assertNull($out['metrics']['ga4_sessions']);
        $this->assertSame(0, $out['metrics']['leads']['total']);
    }

    public function test_stopped_tracking_is_flagged_instead_of_showing_zeros_as_real(): void
    {
        // Registos de março a junho e depois mais nada (como a empresa 3 em dev).
        $this->trackingSince('2026-03-09');
        $this->interaction('whatsapp_click', '2026-06-17 10:00:00');

        $t = $this->build()['sources']['tracking'];   // outubro (mês em curso, hoje 16/10)
        $this->assertSame('stale', $t['state']);
        $this->assertSame('2026-06-17', $t['last_seen']);
        $this->assertSame(121, $t['days_without']);      // 17/06 → 16/10
        $this->assertSame(7, $t['stale_after_days']);

        // Em maio o registo funcionava: não está parado. Em junho parou a 17 (os 13
        // dias seguintes sem nada): já conta como parado.
        $this->assertSame('ok', $this->build('2026-05')['sources']['tracking']['state']);
        $this->assertSame('stale', $this->build('2026-06')['sources']['tracking']['state']);
        // Em julho (mês passado) parou: dias sem registos até ao fim do mês.
        $jul = $this->build('2026-07')['sources']['tracking'];
        $this->assertSame('stale', $jul['state']);
        $this->assertSame(44, $jul['days_without']);   // 17/06 → 31/07
    }

    public function test_recent_tracking_is_ok(): void
    {
        $this->trackingSince('2026-08-01');
        $this->interaction('call_click', '2026-10-12 10:00:00');

        $this->assertSame('ok', $this->build()['sources']['tracking']['state']);
        $this->assertNull($this->build()['sources']['tracking']['days_without']);
    }

    public function test_meta_token_expired_keeps_ingested_data_and_ga4_error_is_a_state(): void
    {
        $this->meta(['status' => 'expired', 'insights_sync_status' => 'token_expired']);
        $this->metaRow('2026-10-02', 50.0, 10);
        CompanyIntegration::create(['company_id' => $this->company->id, 'platform' => 'google', 'access_token' => '', 'property_id' => '1', 'status' => 'active']);
        $this->ga4->throw = true;

        $out = $this->build();

        $this->assertSame('token_expired', $out['sources']['meta']['state']);
        $this->assertSame(50.0, $out['metrics']['meta_spend']['total']);
        $this->assertSame('error', $out['sources']['ga4']['state']);
        $this->assertNull($out['metrics']['ga4_sessions']);
    }

    // ── tenancy + validação ───────────────────────────────────────────────────

    public function test_tenancy_and_month_validation(): void
    {
        $other = $this->makeCompany('500012002', 'Stand B');
        $url = fn (int $id) => "/api/v1/companies/{$id}/analytics/automotive/marketing";

        $this->actingAs($this->user, 'sanctum')->getJson($url($other->id))->assertStatus(403);
        $this->actingAs($this->user, 'sanctum')->getJson($url($this->company->id))->assertOk()->assertJsonPath('data.month', '2026-10');
        $this->actingAs($this->user, 'sanctum')->getJson($url($this->company->id) . '?month=2026-13')->assertStatus(422);
        $this->actingAs($this->user, 'sanctum')->getJson($url($this->company->id) . '?month=2026-11')->assertStatus(422);
        $this->actingAs($this->user, 'sanctum')->getJson($url($this->company->id) . '?month=2026-09')->assertOk()->assertJsonPath('data.month', '2026-09');
    }
}

/** GA4 falso: devolve linhas por canal/dia conforme o início da janela pedida. */
class AutoMarketingFakeGa4 implements Ga4ClientInterface
{
    /** @var array<string, array<int, array{0:string,1:string,2:int}>> */
    public array $channels = [];
    public bool $throw = false;

    public function runReport(int $propertyId, array $spec): array
    {
        if ($this->throw) {
            throw new \RuntimeException('GA4 indisponível');
        }
        if (($spec['dimensions'] ?? []) === ['date', 'sessionDefaultChannelGroup']) {
            $rows = array_map(fn ($r) => ['dimensions' => [$r[0], $r[1]], 'metrics' => [(string) $r[2]]], $this->channels[$spec['start']] ?? []);

            return ['rows' => $rows, 'subjectToThresholding' => false];
        }

        return ['rows' => [], 'subjectToThresholding' => false];
    }
}
