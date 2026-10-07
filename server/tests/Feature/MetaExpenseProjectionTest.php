<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SyncMetaAdInsightsJob;
use App\Models\Car;
use App\Models\CarBrand;
use App\Models\CarModel;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\MetaAd;
use App\Models\MetaAdInsightDaily;
use App\Models\User;
use App\Services\MarginService;
use App\Services\MetaAdCarAllocator;
use App\Services\MetaAdInsightsService;
use App\Services\MetaExpenseProjector;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * XPLENDOR — FASE 2E (A): despesas automáticas do gasto Meta.
 *
 * Cobre: uma despesa por viatura por mês (nunca por dia), upsert idempotente que
 * acompanha as revisões da Meta, mês a zero sai, stock geral e "por atribuir" sem
 * viatura e separados, empresas só com mapeamento manual, viatura apagada (mesma
 * linha, sem viatura) e vendida (pós-venda nas notas), a regra da dupla contagem
 * (opção 1: conta na margem, fora dos totais da página), não editável (409),
 * gatilho na ingestão por anúncio e tenancy.
 */
class MetaExpenseProjectionTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-10-20 10:00:00';

    private Company $company;
    private Company $other;
    private User $user;
    private User $otherUser;
    private int $brandId;
    private int $modelId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::NOW));
        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::NOW));

        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500010001', 'fiscal_name' => 'Stand A', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->other = Company::create(['nipc' => '500010002', 'fiscal_name' => 'Stand B', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
        $this->otherUser = User::factory()->create(['company_id' => $this->other->id, 'role' => 'admin']);
        foreach ([$this->company, $this->other] as $c) {
            $this->enableModule($c, 'finance');
        }

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

    private function enableModule(Company $company, string $module): void
    {
        DB::table('company_modules')->insertOrIgnore([
            'company_id' => $company->id, 'module_key' => $module, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

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

    private function adSpend(string $adId, string $name, float $spend, string $date, ?Company $company = null): void
    {
        $companyId = ($company ?? $this->company)->id;
        $row = MetaAdInsightDaily::where('company_id', $companyId)->where('account_id', '123')
            ->where('ad_id', $adId)->whereDate('date', $date)->first();
        $values = ['campaign_id' => 'C1', 'adset_id' => 'S1', 'ad_name' => $name, 'spend' => $spend, 'impressions' => 100, 'clicks' => 3];
        $row ? $row->update($values) : MetaAdInsightDaily::create(['company_id' => $companyId, 'account_id' => '123', 'date' => $date, 'ad_id' => $adId] + $values);
        MetaAd::updateOrCreate(['company_id' => $companyId, 'account_id' => '123', 'ad_id' => $adId],
            ['ad_name' => $name, 'campaign_id' => 'C1', 'effective_status' => 'ACTIVE']);
        app(MetaAdCarAllocator::class)->rebuild($companyId, '123');
    }

    private function project(array $months = ['2026-09', '2026-10'], ?Company $company = null): array
    {
        return app(MetaExpenseProjector::class)->project(($company ?? $this->company)->id, $months);
    }

    private function auto(): \Illuminate\Database\Eloquent\Builder
    {
        return Expense::where('company_id', $this->company->id)->where('source', Expense::SOURCE_META_ADS);
    }

    // ── upsert mensal idempotente ────────────────────────────────────────────

    public function test_one_expense_per_car_per_month_never_per_day(): void
    {
        $car = $this->car();
        $this->adSpend('A1', "Golf [id:{$car->id}]", 7.0, '2026-09-28');
        $this->adSpend('A1', "Golf [id:{$car->id}]", 10.0, '2026-10-01');
        $this->adSpend('A1', "Golf [id:{$car->id}]", 5.0, '2026-10-02');

        $this->project();

        $rows = $this->auto()->orderBy('date')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['2026-09-30', '2026-10-31'], $rows->map(fn ($e) => $e->date->toDateString())->all());
        $this->assertSame(['7.00', '15.00'], $rows->pluck('amount')->all());
        $this->assertSame([$car->id, $car->id], $rows->pluck('car_id')->all());
        $this->assertSame("meta:car:{$car->id}:2026-10", $rows[1]->source_key);
        $this->assertSame('Meta Ads, outubro 2026 (valor reportado pela Meta, sem IVA)', $rows[1]->description);
        $this->assertTrue($rows[1]->is_paid);

        $category = ExpenseCategory::find($rows[1]->expense_category_id);
        $this->assertSame('Publicidade Meta', $category->name);
        $this->assertSame(ExpenseCategory::SYSTEM_META_ADS, $category->system_key);
    }

    public function test_projection_is_idempotent_and_follows_meta_revisions(): void
    {
        $car = $this->car();
        $this->adSpend('A1', "Golf [id:{$car->id}]", 10.0, '2026-10-18');

        $this->project();
        $id = $this->auto()->value('id');

        // Segunda corrida sem mudanças: nada escrito, mesma linha.
        $again = $this->project();
        $this->assertSame(0, $again['2026-10']['written']);
        $this->assertSame(1, $this->auto()->count());

        // A Meta revê o dia (atribuição atrasada): a mesma linha acompanha.
        $this->adSpend('A1', "Golf [id:{$car->id}]", 12.5, '2026-10-18');
        $this->project();

        $this->assertSame(1, $this->auto()->count());
        $this->assertSame($id, $this->auto()->value('id'));
        $this->assertSame('12.50', $this->auto()->value('amount'));
        $this->assertSame(1, ExpenseCategory::where('company_id', $this->company->id)->where('system_key', ExpenseCategory::SYSTEM_META_ADS)->count());
    }

    public function test_month_that_drops_to_zero_loses_its_automatic_line(): void
    {
        $car = $this->car();
        $this->adSpend('A1', "Golf [id:{$car->id}]", 10.0, '2026-10-18');
        $this->project();

        MetaAdInsightDaily::query()->update(['spend' => 0]);
        app(MetaAdCarAllocator::class)->rebuild($this->company->id, '123');
        $res = $this->project();

        $this->assertSame(1, $res['2026-10']['deleted']);
        $this->assertSame(0, $this->auto()->count());
    }

    // ── stock geral e por atribuir: sem viatura, separados ───────────────────

    public function test_general_stock_and_unattributed_are_company_expenses_without_car(): void
    {
        $car = $this->car();
        $this->adSpend('A1', "Golf [id:{$car->id}]", 40.0, '2026-10-05');
        $this->adSpend('A2', 'Campanha de stock', 25.0, '2026-10-05');
        $this->adSpend('A3', 'Golf [id:999999]', 6.0, '2026-10-05');

        $this->project(['2026-10']);

        $byKey = $this->auto()->get()->keyBy('source_key');
        $this->assertCount(3, $byKey);
        $this->assertSame('25.00', $byKey['meta:general:2026-10']->amount);
        $this->assertNull($byKey['meta:general:2026-10']->car_id);
        $this->assertStringContainsString('stock geral', $byKey['meta:general:2026-10']->description);
        $this->assertSame('6.00', $byKey['meta:unattributed:2026-10']->amount);
        $this->assertNull($byKey['meta:unattributed:2026-10']->car_id);
        $this->assertStringContainsString('por atribuir', $byKey['meta:unattributed:2026-10']->description);
        // A soma das automáticas é o gasto total da conta no mês (nada perdido nem repetido).
        $this->assertEqualsWithDelta(71.0, (float) $this->auto()->sum('amount'), 0.001);
    }

    public function test_company_without_tags_gets_expenses_from_manual_mapping(): void
    {
        $car = $this->car();
        $mappingId = DB::table('car_ad_campaigns')->insertGetId([
            'company_id' => $this->company->id, 'car_id' => $car->id, 'platform' => 'meta', 'campaign_id' => 'C9',
            'level' => 'campaign', 'spend_split_pct' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('campaign_car_metrics_daily')->insert([
            'company_id' => $this->company->id, 'car_id' => $car->id, 'mapping_id' => $mappingId, 'campaign_id' => 'C9',
            'date' => '2026-10-03', 'impressions' => 10, 'clicks' => 1, 'spend_normalized' => 9.99, 'allocation_factor' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->project(['2026-10']);

        $e = $this->auto()->sole();
        $this->assertSame($car->id, $e->car_id);
        $this->assertSame('9.99', $e->amount);
        $this->assertStringContainsString('mapeamento manual', $e->notes);
        $this->assertSame(0, $this->auto()->whereNull('car_id')->count());   // sem ingestão por anúncio: sem stock geral
    }

    // ── viatura apagada / vendida ─────────────────────────────────────────────

    public function test_deleted_car_keeps_the_same_line_without_car(): void
    {
        $car = $this->car();
        $carId = $car->id;
        $this->adSpend('A1', "Golf [id:{$carId}]", 10.0, '2026-10-05');
        $this->project(['2026-10']);
        $id = $this->auto()->value('id');

        $car->delete();
        $this->project(['2026-10']);

        $e = $this->auto()->sole();
        $this->assertSame($id, $e->id);
        $this->assertNull($e->car_id);
        $this->assertSame('10.00', $e->amount);
        $this->assertSame("Meta Ads, outubro 2026, viatura removida n.º {$carId} (valor reportado pela Meta, sem IVA)", $e->description);
    }

    public function test_sold_car_keeps_its_spend_and_notes_the_post_sale_part(): void
    {
        $car = $this->car(['status' => 'sold', 'sold_at' => '2026-10-10 12:00:00']);
        $this->adSpend('A1', "Golf [id:{$car->id}]", 20.0, '2026-10-08');
        $this->adSpend('A1', "Golf [id:{$car->id}]", 4.0, '2026-10-12');

        $this->project(['2026-10']);

        $e = $this->auto()->sole();
        $this->assertSame('24.00', $e->amount);
        $this->assertStringContainsString('Inclui 4,00 € gastos depois da venda (10/10/2026)', $e->notes);
    }

    // ── dupla contagem: opção 1 (repartição analítica) ───────────────────────

    public function test_automatic_expenses_count_in_car_margin_but_stay_out_of_page_totals(): void
    {
        $car = $this->car(['purchase_price' => 15000]);
        $this->adSpend('A1', "Golf [id:{$car->id}]", 15.0, '2026-10-05');
        $this->project(['2026-10']);

        // A fatura mensal da Meta, lançada à mão (sem viatura): é o registo financeiro.
        Expense::create(['company_id' => $this->company->id, 'description' => 'Fatura Meta outubro', 'amount' => 100,
            'date' => '2026-10-31', 'is_paid' => false]);

        $summary = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/companies/{$this->company->id}/expenses/summary")->assertOk()->json('data');

        $this->assertEquals(100.0, $summary['total_amount']);   // só a fatura
        $this->assertEquals(100.0, $summary['open_amount']);
        $this->assertSame(1, $summary['count']);
        $this->assertEquals(15.0, $summary['automatic_meta']['amount']);
        $this->assertFalse($summary['automatic_meta']['in_totals']);

        // Na margem da viatura, a automática conta (a fatura não, não tem viatura).
        $margin = app(MarginService::class)->forCar($this->company->id, $car->id);
        $this->assertSame(15.0, $margin['expenses_total']);
        $this->assertSame(15.0, $margin['expenses_meta_ads_total']);
    }

    public function test_automatic_expense_cannot_be_edited_archived_or_deleted(): void
    {
        $car = $this->car();
        $this->adSpend('A1', "Golf [id:{$car->id}]", 15.0, '2026-10-05');
        $this->project(['2026-10']);
        $id = $this->auto()->value('id');
        $base = "/api/v1/companies/{$this->company->id}/expenses";

        $this->actingAs($this->user, 'sanctum')->putJson("{$base}/{$id}", ['description' => 'x', 'amount' => 1, 'date' => '2026-10-01'])->assertStatus(409);
        $this->actingAs($this->user, 'sanctum')->putJson("{$base}/{$id}", ['description' => 'x', 'amount' => 15, 'date' => '2026-10-31', 'archived' => true])->assertStatus(409);
        $this->actingAs($this->user, 'sanctum')->deleteJson("{$base}/{$id}")->assertStatus(409);

        $row = collect($this->actingAs($this->user, 'sanctum')->getJson($base)->assertOk()->json('data.data'))->firstWhere('id', $id);
        $this->assertTrue($row['is_automatic']);
        $this->assertFalse($row['can_edit']);
        $this->assertFalse($row['can_delete']);

        // Criar à mão nunca produz uma automática.
        $this->actingAs($this->user, 'sanctum')->postJson($base, ['description' => 'Manual', 'amount' => 10, 'date' => '2026-10-02', 'source' => 'meta_ads'])->assertOk();
        $this->assertSame('manual', Expense::where('description', 'Manual')->value('source'));
    }

    // ── gatilho e tenancy ─────────────────────────────────────────────────────

    public function test_ad_level_sync_projects_the_expenses_of_its_window(): void
    {
        $car = $this->car();
        $integration = CompanyIntegration::create(['company_id' => $this->company->id, 'platform' => 'meta', 'access_token' => 'tok',
            'account_id' => '123', 'token_expires_at' => now()->addDays(30), 'status' => 'active',
            'ad_insights_backfilled_at' => now()->subDay(), 'ad_insights_synced_until' => '2026-10-19', 'ad_insights_account_id' => '123']);
        Http::fake(function ($request) use ($car) {
            if (str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/ads')) {
                return Http::response(['data' => []]);
            }

            return Http::response(['data' => [['date_start' => '2026-10-18', 'campaign_id' => 'C1', 'adset_id' => 'S1', 'ad_id' => 'A1',
                'ad_name' => "Golf [id:{$car->id}]", 'spend' => '11.00', 'impressions' => '100', 'clicks' => '2']]]);
        });
        Queue::fake();

        (new SyncMetaAdInsightsJob($integration->id, MetaAdInsightsService::MODE_DAILY))->handle(app(MetaAdInsightsService::class));

        $this->assertSame('11.00', $this->auto()->where('car_id', $car->id)->value('amount'));
    }

    public function test_tenancy_projection_and_endpoints_stay_inside_the_company(): void
    {
        $car = $this->car();
        $this->adSpend('A1', "Golf [id:{$car->id}]", 15.0, '2026-10-05');
        $foreign = $this->car([], $this->other);
        $this->adSpend('B1', "Polo [id:{$foreign->id}]", 50.0, '2026-10-05', $this->other);

        $this->project(['2026-10']);

        $this->assertSame(0, Expense::where('company_id', $this->other->id)->count());   // só a empresa pedida
        $this->assertSame(1, $this->auto()->count());

        $this->actingAs($this->otherUser, 'sanctum')->getJson("/api/v1/companies/{$this->company->id}/expenses")->assertStatus(403);
        $this->actingAs($this->otherUser, 'sanctum')->getJson("/api/v1/companies/{$this->company->id}/expenses/summary")->assertStatus(403);
    }
}
