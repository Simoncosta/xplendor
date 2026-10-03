<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\FetchMetaAdsMetricsJob;
use App\Jobs\SyncMetaAdInsightsJob;
use App\Models\Car;
use App\Models\CarAdAttribution;
use App\Models\CarBrand;
use App\Models\CarModel;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\MetaAd;
use App\Models\MetaAdCarSpendDaily;
use App\Models\MetaAdInsightDaily;
use App\Models\User;
use App\Repositories\CarAdSpendRepository;
use App\Services\AdsGuardrailService;
use App\Services\AttributionService;
use App\Services\CarFunnelAnalyzer;
use App\Services\MetaAdCarAllocator;
use App\Services\MetaAdInsightsService;
use App\Services\MetaAdsCarSyncService;
use App\Services\NextBestCarToPromoteService;
use App\Services\SmartAdsOptimizerService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * XPLENDOR — FASE 2B: ingestão Meta por ANÚNCIO + tag [id:N] + gasto único por viatura.
 *
 * Cobre: tenancy da tag, repartição, inválidos (com aviso), viatura apagada sem
 * perder histórico, pós-venda, PRECEDÊNCIA SEM DUPLA CONTAGEM (tag vs mapeamento
 * manual), backfill mês a mês com paginação e retoma, diário, catálogo
 * (effective_status + tag acrescentada depois), consumidores (sem tags os números
 * ficam iguais; com tags lêem a tag), avisos de qualidade, mapeamento manual só de
 * leitura, job antigo desligado e o ad_id dos leads pagos.
 */
class MetaAdSpendAttributionTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-10-03';

    private int $planId;
    private int $brandId;
    private int $modelId;
    private Company $company;
    private Company $other;
    private User $user;
    private User $otherUser;
    private ?string $graphFailMonth = null;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::TODAY . ' 12:00:00'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::TODAY . ' 12:00:00'));

        $this->planId = DB::table('plans')->insertGetId([
            'name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->company = Company::create(['nipc' => '500007001', 'fiscal_name' => 'Stand A', 'plan_id' => $this->planId, 'subscription_status' => 'active']);
        $this->other = Company::create(['nipc' => '500007002', 'fiscal_name' => 'Stand B', 'plan_id' => $this->planId, 'subscription_status' => 'active']);
        $this->user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
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

    private function car(?Company $company = null, array $attrs = []): Car
    {
        $car = Car::factory()->create(array_merge([
            'company_id' => ($company ?? $this->company)->id, 'car_brand_id' => $this->brandId, 'car_model_id' => $this->modelId,
            'vehicle_type' => 'car', 'status' => 'active', 'price_gross' => 20000,
        ], array_diff_key($attrs, ['sold_at' => 1])));

        if (array_key_exists('sold_at', $attrs)) {
            DB::table('cars')->where('id', $car->id)->update(['sold_at' => $attrs['sold_at']]);
        }

        return $car->fresh();
    }

    private function integration(array $overrides = [], ?Company $company = null): CompanyIntegration
    {
        return CompanyIntegration::create(array_merge([
            'company_id'       => ($company ?? $this->company)->id,
            'platform'         => 'meta',
            'access_token'     => 'tok',
            'account_id'       => '123',
            'token_expires_at' => now()->addDays(30),
            'status'           => 'active',
        ], $overrides));
    }

    /** Simula a ingestão de um anúncio num dia (insights + catálogo). */
    private function adDay(string $date, string $adId, ?string $name, float $spend, string $campaignId = 'C1', int $imp = 1000, int $clicks = 10, ?Company $company = null): void
    {
        $companyId = ($company ?? $this->company)->id;
        MetaAdInsightDaily::create([
            'company_id' => $companyId, 'account_id' => '123', 'date' => $date, 'campaign_id' => $campaignId,
            'adset_id' => 'S1', 'ad_id' => $adId, 'ad_name' => $name, 'spend' => $spend, 'impressions' => $imp, 'clicks' => $clicks,
        ]);
        MetaAd::updateOrCreate(
            ['company_id' => $companyId, 'account_id' => '123', 'ad_id' => $adId],
            ['ad_name' => $name, 'campaign_id' => $campaignId, 'adset_id' => 'S1']
        );
    }

    private function allocate(?Company $company = null): void
    {
        app(MetaAdCarAllocator::class)->rebuild(($company ?? $this->company)->id, '123');
    }

    private function legacy(Car $car, string $campaignId, string $date, float $spend, int $imp = 500, int $clicks = 5, ?string $adId = null): int
    {
        $mappingId = DB::table('car_ad_campaigns')->insertGetId([
            'company_id' => $car->company_id, 'car_id' => $car->id, 'platform' => 'meta',
            'campaign_id' => $campaignId, 'campaign_name' => "Campanha {$campaignId}", 'adset_id' => null, 'ad_id' => $adId,
            'level' => $adId ? 'ad' : 'campaign', 'spend_split_pct' => 100, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('campaign_car_metrics_daily')->insert([
            'company_id' => $car->company_id, 'car_id' => $car->id, 'mapping_id' => $mappingId,
            'campaign_id' => $campaignId, 'adset_id' => null, 'date' => $date,
            'impressions' => $imp, 'clicks' => $clicks, 'spend_normalized' => $spend, 'allocation_factor' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $mappingId;
    }

    /**
     * O view real do funil usa JSON_UNQUOTE (MySQL) e não corre em sqlite. Para testar
     * os consumidores que o juntam ao gasto, troca-se por um view mínimo com as mesmas
     * colunas (só no teste).
     */
    private function sqliteFunnelView(): void
    {
        DB::statement('DROP VIEW IF EXISTS car_funnel_metrics_daily');
        DB::statement(<<<'SQL'
CREATE VIEW car_funnel_metrics_daily AS
SELECT company_id, car_id, DATE(created_at) AS date, COUNT(*) AS sessions, COUNT(*) AS views,
       NULL AS avg_time_on_page, NULL AS scroll, 0 AS whatsapp_clicks, 0 AS form_opens, 0 AS leads
FROM car_views GROUP BY company_id, car_id, DATE(created_at)
SQL);
    }

    private function repo(): CarAdSpendRepository
    {
        return app(CarAdSpendRepository::class);
    }

    private function callPrivate(object $obj, string $method, array $args): mixed
    {
        $m = new \ReflectionMethod($obj, $method);
        $m->setAccessible(true);

        return $m->invokeArgs($obj, $args);
    }

    // ── TAG: atribuição ───────────────────────────────────────────────────────

    public function test_single_tag_attributes_full_spend_to_the_car(): void
    {
        $car = $this->car();
        $this->adDay('2026-10-01', 'A1', "Golf [id:{$car->id}]", 12.34);
        $this->allocate();

        $row = MetaAdCarSpendDaily::sole();
        $this->assertSame($car->id, $row->car_id);
        $this->assertSame($car->id, $row->tagged_car_id);
        $this->assertSame('single', $row->allocation_type);
        $this->assertEqualsWithDelta(1.0, $row->share, 1e-9);
        $this->assertEqualsWithDelta(12.34, $row->spend_allocated, 1e-9);
        $this->assertSame(MetaAd::TAG_MATCHED, MetaAd::where('ad_id', 'A1')->value('tag_status'));
        $this->assertSame(12.34, $this->repo()->totalsForCar($this->company->id, $car->id)['spend']);
    }

    public function test_tag_tenancy_car_of_another_company_is_invalid_and_never_attributed(): void
    {
        $foreign = $this->car($this->other);
        $this->adDay('2026-10-01', 'A1', "Golf [id:{$foreign->id}]", 20.0);
        $this->allocate();

        $this->assertSame(0, MetaAdCarSpendDaily::count());
        $ad = MetaAd::where('ad_id', 'A1')->first();
        $this->assertSame(MetaAd::TAG_INVALID, $ad->tag_status);
        $this->assertSame([(string) $foreign->id], $ad->tag_invalid_ids);
        // A viatura da outra empresa não recebe nada, nem pela fonte única.
        $this->assertSame(0.0, $this->repo()->totalsForCar($this->other->id, $foreign->id)['spend']);
        $this->assertSame(0.0, $this->repo()->totalsForCar($this->company->id, $foreign->id)['spend']);

        $warnings = $this->repo()->invalidTagWarnings($this->company->id);
        $this->assertCount(1, $warnings);
        $this->assertSame(['' . $foreign->id], $warnings[0]['invalid_ids']);
        $this->assertSame(20.0, $warnings[0]['spend_recent']);
    }

    public function test_nonexistent_id_is_invalid_with_warning(): void
    {
        $this->adDay('2026-10-01', 'A1', 'Golf [id:999999]', 5.0);
        $this->allocate();

        $this->assertSame(0, MetaAdCarSpendDaily::count());
        $this->assertSame(MetaAd::TAG_INVALID, MetaAd::where('ad_id', 'A1')->value('tag_status'));
        $this->assertCount(1, $this->repo()->invalidTagWarnings($this->company->id));
    }

    public function test_untagged_goes_to_general_stock_without_warning(): void
    {
        $this->car();
        $this->adDay('2026-10-01', 'A1', 'Stock geral outubro', 30.0);
        $this->allocate();

        $this->assertSame(0, MetaAdCarSpendDaily::count());
        $this->assertSame(MetaAd::TAG_UNTAGGED, MetaAd::where('ad_id', 'A1')->value('tag_status'));
        $this->assertSame([], $this->repo()->invalidTagWarnings($this->company->id));
        $this->assertFalse($this->repo()->usesTags($this->company->id));
    }

    public function test_split_tag_shares_spend_in_equal_parts_labelled_split(): void
    {
        $a = $this->car();
        $b = $this->car();
        $c = $this->car();
        $this->adDay('2026-10-01', 'A1', "Trio [id:{$a->id},{$b->id},{$c->id}]", 10.0, 'C1', 1001, 7);
        $this->allocate();

        $rows = MetaAdCarSpendDaily::orderBy('id')->get();
        $this->assertCount(3, $rows);
        $this->assertSame(['split', 'split', 'split'], $rows->pluck('allocation_type')->all());
        $this->assertEqualsWithDelta(10.0, $rows->sum('spend_allocated'), 1e-9);   // soma exacta
        $this->assertEqualsWithDelta(1001, $rows->sum('impressions_allocated'), 1e-9);
        $this->assertEqualsWithDelta(0.333333, $rows[0]->share, 1e-9);
        $this->assertSame(MetaAd::TAG_SPLIT, MetaAd::where('ad_id', 'A1')->value('tag_status'));

        $summary = $this->repo()->carSpendSummary($this->company->id, $a->id);
        $this->assertSame(3.33, $summary['spend']);
        $this->assertSame(3.33, $summary['split_spend']);
        $this->assertSame(0.0, $summary['single_spend']);
    }

    public function test_mixed_valid_and_invalid_ids_split_only_valid_and_warn_invalid(): void
    {
        $a = $this->car();
        $b = $this->car();
        $foreign = $this->car($this->other);
        $this->adDay('2026-10-01', 'A1', "Mix [id:{$a->id}, {$foreign->id}, {$b->id}]", 9.0);
        $this->allocate();

        $rows = MetaAdCarSpendDaily::orderBy('tagged_car_id')->get();
        $this->assertSame([$a->id, $b->id], $rows->pluck('tagged_car_id')->sort()->values()->all());
        $this->assertEqualsWithDelta(4.5, $rows[0]->spend_allocated, 1e-9);
        $ad = MetaAd::where('ad_id', 'A1')->first();
        $this->assertSame(MetaAd::TAG_SPLIT, $ad->tag_status);
        $this->assertSame([(string) $foreign->id], $ad->tag_invalid_ids);
        $this->assertSame([(string) $foreign->id], $this->repo()->invalidTagWarnings($this->company->id)[0]['invalid_ids']);
    }

    public function test_deleted_car_keeps_history_as_removed_car(): void
    {
        $car = $this->car();
        $carId = $car->id;
        $this->adDay('2026-09-20', 'A1', "Golf [id:{$carId}]", 15.0);
        $this->allocate();

        $car->delete();

        // Sem cascade: a linha fica, marcada como viatura removida.
        $row = MetaAdCarSpendDaily::sole();
        $this->assertNull($row->car_id);
        $this->assertSame($carId, $row->tagged_car_id);
        $this->assertSame([$carId => 15.0], $this->repo()->removedCarsSpend($this->company->id));
        $this->assertSame(15.0, $this->repo()->companyTotals($this->company->id)['spend']);

        // Gasto novo do mesmo anúncio + reconstrução total: continua atribuído como
        // removida (não vira tag inválida nem cai no stock geral).
        $this->adDay('2026-09-21', 'A1', "Golf [id:{$carId}]", 5.0);
        $this->allocate();

        $this->assertSame(2, MetaAdCarSpendDaily::whereNull('car_id')->where('tagged_car_id', $carId)->count());
        $this->assertSame(MetaAd::TAG_MATCHED, MetaAd::where('ad_id', 'A1')->value('tag_status'));
        $this->assertSame([], $this->repo()->invalidTagWarnings($this->company->id));
        $this->assertSame([$carId => 20.0], $this->repo()->removedCarsSpend($this->company->id));
    }

    public function test_sold_car_keeps_spend_and_post_sale_is_flagged(): void
    {
        $car = $this->car(null, ['status' => 'sold', 'sold_at' => '2026-09-15 16:00:00']);
        $this->adDay('2026-09-14', 'A1', "Golf [id:{$car->id}]", 10.0);
        $this->adDay('2026-09-15', 'A1', "Golf [id:{$car->id}]", 4.0);   // dia da venda = antes
        $this->adDay('2026-09-16', 'A1', "Golf [id:{$car->id}]", 3.0);
        $this->adDay('2026-09-20', 'A1', "Golf [id:{$car->id}]", 2.0);
        $this->allocate();

        $s = $this->repo()->carSpendSummary($this->company->id, $car->id);
        $this->assertSame(19.0, $s['spend']);          // gasto continua atribuído (ROI da venda)
        $this->assertSame(14.0, $s['pre_sale_spend']);
        $this->assertSame(5.0, $s['post_sale_spend']);
        $this->assertSame('2026-09-15', $s['sold_at']);
    }

    // ── PRECEDÊNCIA: nunca somar tag + mapeamento manual ─────────────────────

    public function test_precedence_tag_wins_and_never_double_counts(): void
    {
        $this->sqliteFunnelView();
        $car = $this->car();

        // Mapeamento manual antigo: C1 (que vai ter tag) e C2 (campanha antiga sem tag).
        $this->legacy($car, 'C1', '2026-10-01', 50.0, 900, 9);
        $this->legacy($car, 'C2', '2026-10-01', 7.0, 100, 1);

        // Sem tags: só legado.
        $this->assertSame(57.0, $this->repo()->totalsForCar($this->company->id, $car->id)['spend']);

        // O mesmo gasto de C1 chega agora pela tag (o legado de C1 era o mesmo dinheiro).
        $this->adDay('2026-10-01', 'A1', "Golf [id:{$car->id}]", 40.0, 'C1', 800, 8);
        $this->allocate();

        $this->assertTrue($this->repo()->usesTags($this->company->id));
        $t = $this->repo()->totalsForCar($this->company->id, $car->id);
        // 40 (tag de C1) + 7 (legado de C2) — NUNCA 97 (50 + 40 + 7).
        $this->assertSame(47.0, $t['spend']);
        $this->assertSame(900, $t['impressions']);
        $this->assertSame(9, $t['clicks']);

        $s = $this->repo()->carSpendSummary($this->company->id, $car->id);
        $this->assertSame(40.0, $s['tag_spend']);
        $this->assertSame(7.0, $s['legacy_spend']);
        $this->assertSame(47.0, $this->repo()->companyTotals($this->company->id)['spend']);
        $this->assertSame(['2026-10-01' => 47.0], $this->repo()->dailySpendForCar($this->company->id, $car->id, '2026-10-01', '2026-10-03'));

        // Os consumidores migrados lêem o mesmo número (sem dupla contagem).
        $next = $this->callPrivate(app(NextBestCarToPromoteService::class), 'campaignMetrics', [$this->company->id, [$car->id]]);
        $this->assertSame(47.0, $next[$car->id]['spend_last_7d']);
        $funnel = $this->callPrivate(app(CarFunnelAnalyzer::class), 'loadMetricsForCars', [collect([$car]), '2026-09-27', self::TODAY]);
        $this->assertSame(47.0, $funnel[$car->id]['spend_normalized']);
        $guard = $this->callPrivate(app(AdsGuardrailService::class), 'aggregateCampaignMetrics', [$car, Carbon::parse('2026-09-27'), Carbon::parse(self::TODAY)]);
        $this->assertSame(47.0, $guard['spend_normalized']);
    }

    public function test_campaign_with_only_invalid_tag_also_excludes_legacy(): void
    {
        $car = $this->car();
        $this->legacy($car, 'C1', '2026-10-01', 50.0);
        $this->adDay('2026-10-01', 'A1', 'Golf [id:999999]', 50.0, 'C1');
        $this->allocate();

        // A empresa passou a usar tags; a campanha C1 tem tag (inválida): o gasto vai
        // para o aviso, não volta pelo mapeamento manual.
        $this->assertSame(0.0, $this->repo()->totalsForCar($this->company->id, $car->id)['spend']);
        $this->assertCount(1, $this->repo()->invalidTagWarnings($this->company->id));
    }

    // ── NÃO-REGRESSÃO: sem tags, os consumidores dão os números de antes ─────

    public function test_without_tags_consumers_read_exactly_the_legacy_numbers(): void
    {
        $this->sqliteFunnelView();
        $car = $this->car();
        $other = $this->car();
        $m1 = $this->legacy($car, 'C1', now()->subDays(2)->toDateString(), 12.5, 300, 3, 'AD1');
        $this->legacy($car, 'C2', now()->subDays(1)->toDateString(), 7.25, 200, 2);
        $this->legacy($other, 'C3', now()->subDays(1)->toDateString(), 3.0, 50, 1);
        $from = now()->subDays(6)->toDateString();
        $to = now()->toDateString();

        // Valores exactamente como as queries antigas os calculavam.
        $legacySum = fn (int $carId) => DB::table('campaign_car_metrics_daily')->where('company_id', $this->company->id)
            ->where('car_id', $carId)->whereBetween('date', [$from, $to])
            ->selectRaw('SUM(impressions) i, SUM(clicks) c, SUM(spend_normalized) s')->first();

        $old = $legacySum($car->id);
        $funnel = $this->callPrivate(app(CarFunnelAnalyzer::class), 'loadMetricsForCars', [collect([$car, $other]), $from, $to]);
        $this->assertSame((int) $old->i, $funnel[$car->id]['impressions']);
        $this->assertSame((int) $old->c, $funnel[$car->id]['clicks']);
        $this->assertSame(round((float) $old->s, 2), $funnel[$car->id]['spend_normalized']);
        $this->assertSame(3.0, $funnel[$other->id]['spend_normalized']);

        $guard = $this->callPrivate(app(AdsGuardrailService::class), 'aggregateCampaignMetrics', [$car, Carbon::parse($from), Carbon::parse($to)]);
        $this->assertSame(['impressions' => 500, 'clicks' => 5, 'spend_normalized' => 19.75], $guard);

        $daily = $this->callPrivate(app(AdsGuardrailService::class), 'loadDailyRiskRows', [$car, Carbon::parse($from), Carbon::parse($to)]);
        $this->assertSame([12.5, 7.25], array_column($daily, 'spend'));
        $this->assertSame(['avg_time_on_page', 'date', 'form_opens', 'leads', 'scroll', 'spend', 'whatsapp_clicks'], collect(array_keys($daily[0]))->sort()->values()->all());

        $next = $this->callPrivate(app(NextBestCarToPromoteService::class), 'campaignMetrics', [$this->company->id, [$car->id, $other->id]]);
        $this->assertSame(['active_campaigns' => 2, 'spend_last_7d' => 19.75], $next[$car->id]);
        $this->assertSame(['active_campaigns' => 1, 'spend_last_7d' => 3.0], $next[$other->id]);

        $targets = $this->callPrivate(app(SmartAdsOptimizerService::class), 'targetRows', [$car, $from, $to]);
        $this->assertCount(2, $targets);
        $this->assertSame($m1, $targets[0]->mapping_id);
        $this->assertSame(['C1', 'AD1', 12.5], [$targets[0]->campaign_id, $targets[0]->ad_id, $targets[0]->spend_normalized]);

        $this->assertFalse($this->repo()->usesTags($this->company->id));
    }

    // ── INGESTÃO: backfill mês a mês com paginação ───────────────────────────

    /** @return array{0: list<array>, 1: callable} */
    private function fakeGraph(array $rowsByMonth, ?string $failMonth = null): array
    {
        $log = [];
        $this->graphFailMonth = $failMonth;
        Http::fake(function (HttpRequest $request) use (&$log, $rowsByMonth) {
            $failMonth = $this->graphFailMonth;
            $path = parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
            $log[] = ['path' => $path, 'q' => $q];

            if (str_ends_with($path, '/ads')) {
                return Http::response(['data' => [
                    ['id' => 'A1', 'name' => 'Golf [id:1]', 'effective_status' => 'ACTIVE', 'campaign_id' => 'C1', 'adset_id' => 'S1'],
                ]]);
            }

            $range = json_decode($q['time_range'] ?? '{}', true);
            $month = substr($range['since'] ?? '', 0, 7);

            if ($failMonth !== null && $month === $failMonth) {
                return Http::response(['error' => ['code' => 100, 'message' => 'Invalid parameter']], 400);
            }

            $rows = $rowsByMonth[$month] ?? [];
            // 2 páginas para o 1.º mês com dados: prova a paginação.
            if (count($rows) > 1 && ! isset($q['after'])) {
                $next = 'https://graph.facebook.com/v25.0/act_123/insights?' . http_build_query(array_merge($q, ['after' => 'CUR']));

                return Http::response(['data' => [array_shift($rows)], 'paging' => ['next' => $next]]);
            }
            if (count($rows) > 1) {
                array_shift($rows);
            }

            return Http::response(['data' => $rows]);
        });

        return [$log, function () use (&$log) {
            return $log;
        }];
    }

    private function graphRow(string $date, string $adId, string $name, float $spend, string $campaign = 'C1'): array
    {
        return ['date_start' => $date, 'date_stop' => $date, 'campaign_id' => $campaign, 'adset_id' => 'S1',
            'ad_id' => $adId, 'ad_name' => $name, 'spend' => (string) $spend, 'impressions' => '100', 'clicks' => '2'];
    }

    public function test_backfill_runs_month_by_month_with_pagination_and_builds_attribution(): void
    {
        $car = $this->car();
        $integration = $this->integration();
        $name = "Golf [id:{$car->id}]";

        [, $getLog] = $this->fakeGraph([
            '2025-09' => [$this->graphRow('2025-09-03', 'A1', $name, 10.0), $this->graphRow('2025-09-04', 'A1', $name, 5.0)],
            '2026-10' => [$this->graphRow('2026-10-02', 'A1', $name, 2.5)],
        ]);

        $res = app(MetaAdInsightsService::class)->sync($integration, MetaAdInsightsService::MODE_BACKFILL);

        $this->assertSame('done', $res['result']);
        $this->assertSame(14, $res['months']);   // 2025-09 … 2026-10

        $insights = collect($getLog())->filter(fn ($r) => str_ends_with($r['path'], '/insights'))->values();
        $this->assertCount(15, $insights);       // 14 meses + 2.ª página do 1.º
        foreach ($insights as $r) {
            $range = json_decode($r['q']['time_range'], true);
            $this->assertSame(substr($range['since'], 0, 7), substr($range['until'], 0, 7), 'cada pedido é UM mês');
            $this->assertSame('ad', $r['q']['level']);
            $this->assertSame('tok', $r['q']['access_token']);   // a 2.ª página não perde o token
            $this->assertStringContainsString('ad.effective_status', $r['q']['filtering']);
        }
        $first = json_decode($insights[0]['q']['time_range'], true);
        $this->assertSame(['since' => '2025-09-01', 'until' => '2025-09-30'], $first);
        $this->assertSame('CUR', $insights[1]['q']['after']);
        $last = json_decode($insights->last()['q']['time_range'], true);
        $this->assertSame(['since' => '2026-10-01', 'until' => self::TODAY], $last);

        $this->assertSame(3, MetaAdInsightDaily::count());
        $this->assertSame(17.5, $this->repo()->totalsForCar($this->company->id, $car->id)['spend']);
        $this->assertSame('ACTIVE', MetaAd::where('ad_id', 'A1')->value('effective_status'));

        $integration->refresh();
        $this->assertSame('done', $integration->ad_insights_sync_status);
        $this->assertNotNull($integration->ad_insights_backfilled_at);
        $this->assertNull($integration->ad_insights_backfill_cursor);
        $this->assertSame(self::TODAY, $integration->ad_insights_synced_until->toDateString());
        $this->assertSame('123', $integration->ad_insights_account_id);
    }

    public function test_backfill_failure_keeps_done_months_and_resumes_from_failed_month(): void
    {
        $integration = $this->integration();
        [, $getLog] = $this->fakeGraph(['2025-09' => [$this->graphRow('2025-09-03', 'A1', 'x', 10.0)]], '2025-11');

        $res = app(MetaAdInsightsService::class)->sync($integration, MetaAdInsightsService::MODE_BACKFILL);

        $this->assertSame('failed', $res['result']);
        $integration->refresh();
        $this->assertSame('failed', $integration->ad_insights_sync_status);
        $this->assertSame('2025-11-01', $integration->ad_insights_backfill_cursor->toDateString());
        $this->assertNull($integration->ad_insights_backfilled_at);
        $this->assertSame(1, MetaAdInsightDaily::count());   // Setembro ficou

        // Próxima corrida (a Meta já responde): começa no mês que falhou, não refaz os anteriores.
        $this->graphFailMonth = null;
        $res = app(MetaAdInsightsService::class)->sync($integration->fresh(), MetaAdInsightsService::MODE_DAILY);

        $this->assertSame('done', $res['result']);
        $this->assertSame('backfill', $res['mode']);   // sem backfill concluído, o diário sobe a backfill
        $insights = collect($getLog())->filter(fn ($r) => str_ends_with($r['path'], '/insights'))->values();
        // 1.ª corrida: Set, Out, Nov (falhou). 2.ª corrida começa em Novembro.
        $this->assertSame('2025-11-01', json_decode($insights[2]['q']['time_range'], true)['since']);
        $this->assertSame('2025-11-01', json_decode($insights[3]['q']['time_range'], true)['since']);
        $this->assertCount(3 + 12, $insights);   // 2.ª corrida: Nov 2025 … Out 2026
        $this->assertSame(1, MetaAdInsightDaily::count());
    }

    public function test_job_fetches_one_month_per_run_and_requeues_itself(): void
    {
        $integration = $this->integration();
        $this->fakeGraph([]);
        Queue::fake();

        (new SyncMetaAdInsightsJob($integration->id, MetaAdInsightsService::MODE_BACKFILL))
            ->handle(app(MetaAdInsightsService::class));

        $this->assertSame('2025-10-01', $integration->fresh()->ad_insights_backfill_cursor->toDateString());
        Queue::assertPushed(SyncMetaAdInsightsJob::class, fn ($j) => $j->integrationId === $integration->id && $j->mode === 'backfill');
    }

    public function test_throttling_is_retryable_and_keeps_cursor(): void
    {
        $integration = $this->integration(['ad_insights_backfill_cursor' => '2026-02-01']);
        Http::fake(['*' => Http::response(['error' => ['code' => 17, 'message' => 'User request limit reached']], 400)]);

        $res = app(MetaAdInsightsService::class)->sync($integration, MetaAdInsightsService::MODE_BACKFILL);

        $this->assertSame('retryable', $res['result']);
        $this->assertSame('pending', $integration->fresh()->ad_insights_sync_status);
        $this->assertSame('2026-02-01', $integration->fresh()->ad_insights_backfill_cursor->toDateString());
    }

    public function test_daily_replaces_last_three_days_and_keeps_older_history(): void
    {
        $car = $this->car();
        $integration = $this->integration(['ad_insights_backfilled_at' => now()->subDay(), 'ad_insights_synced_until' => '2026-10-02', 'ad_insights_account_id' => '123']);
        $name = "Golf [id:{$car->id}]";
        $this->adDay('2026-09-10', 'A1', $name, 8.0);    // fora da janela: fica
        $this->adDay('2026-10-01', 'A1', $name, 99.0);   // dentro da janela: substituído
        $this->allocate();

        [, $getLog] = $this->fakeGraph(['2026-09' => [], '2026-10' => [$this->graphRow('2026-10-01', 'A1', $name, 3.0)]]);
        $res = app(MetaAdInsightsService::class)->sync($integration, MetaAdInsightsService::MODE_DAILY);

        $this->assertSame('daily', $res['mode']);
        $this->assertSame(['2026-09-30', self::TODAY], [$res['since'], $res['until']]);
        $this->assertSame(11.0, $this->repo()->totalsForCar($this->company->id, $car->id)['spend']);
    }

    public function test_tag_added_later_to_ad_name_applies_to_its_whole_history(): void
    {
        $car = $this->car();
        $integration = $this->integration(['ad_insights_backfilled_at' => now()->subDay(), 'ad_insights_synced_until' => '2026-10-02', 'ad_insights_account_id' => '123']);
        $this->adDay('2026-08-10', 'A1', 'Golf GTI', 8.0);   // sem tag na altura
        $this->allocate();
        $this->assertSame(0, MetaAdCarSpendDaily::count());

        // O catálogo devolve o nome actual, agora com a tag.
        Http::fake(function (HttpRequest $request) use ($car) {
            if (str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/ads')) {
                return Http::response(['data' => [['id' => 'A1', 'name' => "Golf GTI [id:{$car->id}]", 'effective_status' => 'PAUSED', 'campaign_id' => 'C1']]]);
            }

            return Http::response(['data' => []]);
        });
        app(MetaAdInsightsService::class)->sync($integration, MetaAdInsightsService::MODE_DAILY);

        $this->assertSame(8.0, $this->repo()->totalsForCar($this->company->id, $car->id)['spend']);
        $this->assertSame('PAUSED', MetaAd::where('ad_id', 'A1')->value('effective_status'));
    }

    public function test_ad_missing_from_catalog_loses_active_status(): void
    {
        $integration = $this->integration(['ad_insights_backfilled_at' => now()->subDay(), 'ad_insights_synced_until' => '2026-10-02', 'ad_insights_account_id' => '123']);
        MetaAd::create(['company_id' => $this->company->id, 'account_id' => '123', 'ad_id' => 'OLD', 'effective_status' => 'ACTIVE']);
        $this->fakeGraph([]);

        app(MetaAdInsightsService::class)->sync($integration, MetaAdInsightsService::MODE_DAILY);

        $this->assertNull(MetaAd::where('ad_id', 'OLD')->value('effective_status'));
        $this->assertSame('ACTIVE', MetaAd::where('ad_id', 'A1')->value('effective_status'));
    }

    public function test_account_change_purges_old_account_ad_data(): void
    {
        $car = $this->car();
        $this->adDay('2026-09-10', 'A1', "Golf [id:{$car->id}]", 8.0);
        $this->allocate();
        $integration = $this->integration(['account_id' => '999', 'ad_insights_account_id' => '123', 'ad_insights_backfilled_at' => now()->subDay()]);
        $this->fakeGraph([]);

        $res = app(MetaAdInsightsService::class)->sync($integration, MetaAdInsightsService::MODE_DAILY);

        $this->assertSame('backfill', $res['mode']);
        $this->assertSame(0, MetaAdInsightDaily::where('account_id', '123')->count());
        $this->assertSame(0, MetaAdCarSpendDaily::where('account_id', '123')->count());
        $this->assertSame('999', $integration->fresh()->ad_insights_account_id);
    }

    public function test_token_expired_is_recorded_without_calling_meta(): void
    {
        Http::fake();
        $integration = $this->integration(['token_expires_at' => now()->subDay()]);

        $this->assertSame('token_expired', app(MetaAdInsightsService::class)->sync($integration)['result']);
        $this->assertSame('token_expired', $integration->fresh()->ad_insights_sync_status);
        Http::assertNothingSent();
    }

    // ── AVISOS: endpoint + tenancy ────────────────────────────────────────────

    public function test_warnings_endpoint_lists_invalid_tags_and_enforces_tenancy(): void
    {
        $this->adDay('2026-10-01', 'A1', 'Golf [id:999999]', 6.0);
        $this->allocate();
        $url = "/api/v1/companies/{$this->company->id}/analytics/meta/ad-tag-warnings";

        $this->actingAs($this->otherUser, 'sanctum')->getJson($url)->assertStatus(403);

        $res = $this->actingAs($this->user, 'sanctum')->getJson($url)->assertOk();
        $this->assertSame(1, $res->json('data.count'));
        $this->assertSame(['999999'], $res->json('data.warnings.0.invalid_ids'));
        $this->assertTrue($res->json('data.uses_tags'));
    }

    // ── MAPEAMENTO MANUAL só de leitura + job antigo desligado ───────────────

    public function test_manual_mapping_is_read_only_once_company_uses_tags_and_enforces_tenancy(): void
    {
        $car = $this->car();
        $base = "/api/v1/companies/{$this->company->id}/cars/{$car->id}/ad-campaigns";
        $payload = ['platform' => 'meta', 'campaign_id' => 'C9', 'spend_split_pct' => 100];

        // Outra empresa: 403 (leitura e escrita).
        $this->actingAs($this->otherUser, 'sanctum')->getJson($base)->assertStatus(403);
        $this->actingAs($this->otherUser, 'sanctum')->postJson($base, $payload)->assertStatus(403);

        // Sem tags: continua a funcionar como antes.
        $this->actingAs($this->user, 'sanctum')->postJson($base, $payload)->assertOk();

        $this->adDay('2026-10-01', 'A1', "Golf [id:{$car->id}]", 1.0);
        $this->allocate();

        $this->actingAs($this->user, 'sanctum')->postJson($base, ['platform' => 'meta', 'campaign_id' => 'C10', 'spend_split_pct' => 100])->assertStatus(409);
        $mappingId = DB::table('car_ad_campaigns')->where('car_id', $car->id)->value('id');
        $this->actingAs($this->user, 'sanctum')->deleteJson("{$base}/{$mappingId}")->assertStatus(409);
        $this->actingAs($this->user, 'sanctum')->patchJson("{$base}/{$mappingId}/toggle")->assertStatus(409);
        $this->actingAs($this->user, 'sanctum')->getJson($base)->assertOk();   // leitura continua
        $this->assertSame(1, DB::table('car_ad_campaigns')->where('car_id', $car->id)->count());
    }

    public function test_legacy_job_skips_companies_that_use_tags(): void
    {
        $tagged = $this->car();
        $legacyCar = $this->car($this->other);
        $this->integration();
        $this->integration([], $this->other);
        $this->legacy($tagged, 'C1', '2026-09-01', 1.0);
        $legacyMapping = $this->legacy($legacyCar, 'C7', '2026-09-01', 1.0);
        $this->adDay('2026-10-01', 'A1', "Golf [id:{$tagged->id}]", 1.0);
        $this->allocate();

        $sync = Mockery::mock(MetaAdsCarSyncService::class);
        $sync->shouldReceive('refreshMappingForDay')->once()
            ->withArgs(fn ($integration, $mapping) => $mapping->id === $legacyMapping)
            ->andReturn([]);

        (new FetchMetaAdsMetricsJob(Carbon::parse('2026-10-02')))->handle($sync);
    }

    // ── LEADS PAGOS: ad_id na atribuição ──────────────────────────────────────

    private function trackVisit(Car $car, array $tracking): ?CarAdAttribution
    {
        $request = Request::create('/api/public/track', 'POST', ['tracking' => array_merge([
            'visitor_id' => 'v-1', 'session_id' => 's-1',
        ], $tracking)]);

        return app(AttributionService::class)->trackVisit($car, $request);
    }

    public function test_paid_meta_visit_stores_ad_id_for_the_link_to_ad_insights(): void
    {
        $car = $this->car();
        $a = $this->trackVisit($car, [
            'utm_source' => 'meta', 'utm_medium' => 'paid', 'utm_content' => '120211234567890', 'ad_id' => '120211234567890', 'channel' => 'paid',
        ]);

        $this->assertSame('120211234567890', $a->ad_id);
        $this->assertSame('paid', $a->utm_medium);
        $this->assertSame('meta', $a->platform);
    }

    public function test_unreplaced_macro_is_not_an_ad_id_and_utm_content_is_the_fallback(): void
    {
        $car = $this->car();
        $macro = $this->trackVisit($car, ['utm_source' => 'meta', 'utm_medium' => 'paid', 'ad_id' => '{{ad.id}}', 'utm_content' => '{{ad.id}}']);
        $this->assertNull($macro->ad_id);

        $other = $this->car();
        $fallback = $this->trackVisit($other, ['utm_source' => 'meta', 'utm_medium' => 'paid', 'utm_content' => '120219999999999']);
        $this->assertSame('120219999999999', $fallback->ad_id);
    }
}
