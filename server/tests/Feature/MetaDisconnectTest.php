<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Car;
use App\Models\CarBrand;
use App\Models\CarModel;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * XPLENDOR — Desligar a Meta (App Review).
 *
 * Cobre: retirada da autorização na Meta (DELETE /me/permissions) ANTES de apagar
 * o token, com token válido e expirado; desligar mantendo o histórico (omissão);
 * desligar e apagar todos os dados da Meta, só da própria empresa, mantendo as
 * vendas; confirmação explícita; tenancy; scope do OAuth só com ads_read.
 */
class MetaDisconnectTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSIONS_URL = 'graph.facebook.com/v25.0/me/permissions*';
    private const SOLD_AT = '2026-06-09 17:32:23';

    private Company $companyA;
    private Company $companyB;
    private User $adminA;
    private Car $carA;
    private Car $carB;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-05 10:00:00'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-05 10:00:00'));

        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->companyA = Company::create(['nipc' => '500014001', 'fiscal_name' => 'Stand A', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->companyB = Company::create(['nipc' => '500014002', 'fiscal_name' => 'Stand B', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->adminA = User::factory()->create(['company_id' => $this->companyA->id, 'role' => 'admin']);

        $brand = CarBrand::firstOrCreate(['slug' => 'vw'], ['name' => 'VW', 'vehicle_type' => 'car']);
        $model = CarModel::firstOrCreate(['name' => 'Golf', 'car_brand_id' => $brand->id]);
        $this->carA = Car::factory()->create(['company_id' => $this->companyA->id, 'car_brand_id' => $brand->id, 'car_model_id' => $model->id, 'status' => 'sold']);
        $this->carB = Car::factory()->create(['company_id' => $this->companyB->id, 'car_brand_id' => $brand->id, 'car_model_id' => $model->id, 'status' => 'sold']);

        $this->integration($this->companyA, 'tok-A');
        $this->integration($this->companyB, 'tok-B');
        $this->seedMetaData($this->companyA, $this->carA);
        $this->seedMetaData($this->companyB, $this->carB);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function url(Company $company): string
    {
        return "/api/v1/companies/{$company->id}/integrations/meta";
    }

    private function integration(Company $company, string $token, string $status = 'active'): CompanyIntegration
    {
        return CompanyIntegration::create([
            'company_id' => $company->id, 'platform' => 'meta', 'access_token' => $token,
            'account_id' => 'act_' . $company->id, 'token_expires_at' => now()->addDays(30), 'status' => $status,
        ]);
    }

    private function seedMetaData(Company $company, Car $car): void
    {
        $c = $company->id;
        $t = ['created_at' => now(), 'updated_at' => now()];
        $acc = 'act_' . $c;

        DB::table('meta_account_insights_daily')->insert($t + ['company_id' => $c, 'account_id' => $acc, 'date' => '2026-09-01', 'campaign_id' => 'C1', 'campaign_name' => 'Campanha', 'spend' => 10, 'impressions' => 100, 'clicks' => 5]);
        DB::table('meta_ad_insights_daily')->insert($t + ['company_id' => $c, 'account_id' => $acc, 'date' => '2026-09-01', 'campaign_id' => 'C1', 'adset_id' => 'S1', 'ad_id' => 'A1', 'ad_name' => 'Anúncio [id:' . $car->id . ']', 'spend' => 10, 'impressions' => 100, 'clicks' => 5]);
        DB::table('meta_ads')->insert($t + ['company_id' => $c, 'account_id' => $acc, 'ad_id' => 'A1', 'ad_name' => 'Anúncio', 'campaign_id' => 'C1', 'adset_id' => 'S1', 'effective_status' => 'ACTIVE']);
        DB::table('meta_ad_car_spend_daily')->insert($t + ['company_id' => $c, 'account_id' => $acc, 'date' => '2026-09-01', 'campaign_id' => 'C1', 'adset_id' => 'S1', 'ad_id' => 'A1', 'car_id' => $car->id, 'tagged_car_id' => $car->id, 'share' => 1, 'spend_allocated' => 10, 'impressions_allocated' => 100, 'clicks_allocated' => 5, 'allocation_type' => 'tag']);
        DB::table('meta_custom_audiences')->insert($t + ['company_id' => $c, 'account_id' => $acc, 'audience_id' => 'AU1', 'name' => 'Visitantes', 'fetched_at' => now()]);
        DB::table('meta_audience_insights')->insert($t + ['company_id' => $c, 'car_id' => $car->id, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'age_range' => '25-34', 'gender' => 'male', 'impressions' => 50, 'clicks' => 2, 'spend' => 5, 'reach' => 40]);
        $mappingId = DB::table('car_ad_campaigns')->insertGetId($t + ['company_id' => $c, 'car_id' => $car->id, 'platform' => 'meta', 'campaign_id' => 'C1', 'campaign_name' => 'Campanha', 'level' => 'campaign', 'is_active' => true]);
        DB::table('campaign_car_metrics_daily')->insert($t + ['company_id' => $c, 'car_id' => $car->id, 'mapping_id' => $mappingId, 'campaign_id' => 'C1', 'date' => '2026-09-01', 'impressions' => 100, 'clicks' => 5, 'spend_normalized' => 10]);

        foreach (['meta_ads' => '2026-09', 'manual' => '2026-08'] as $source => $month) {
            DB::table('car_performance_metrics')->insert($t + ['company_id' => $c, 'car_id' => $car->id, 'channel' => 'paid', 'data_source' => $source, 'period_start' => $month . '-01', 'period_end' => $month . '-28', 'spend_amount' => 10]);
        }
        $categoryId = DB::table('expense_categories')->insertGetId($t + ['company_id' => $c, 'name' => 'Marketing ' . $c]);
        DB::table('expenses')->insert($t + ['company_id' => $c, 'source' => 'meta_ads', 'source_key' => 'meta:2026-09:' . $c, 'description' => 'Meta Ads', 'amount' => 10, 'date' => '2026-09-30', 'expense_category_id' => $categoryId]);
        DB::table('expenses')->insert($t + ['company_id' => $c, 'description' => 'Fotografias', 'amount' => 50, 'date' => '2026-09-15', 'expense_category_id' => $categoryId]);

        $saleId = DB::table('car_sales')->insertGetId($t + ['car_id' => $car->id, 'company_id' => $c, 'sale_price' => 15000,
            'buyer_gender' => 'male', 'buyer_age_range' => '31-45', 'sale_channel' => 'walk_in', 'sold_at' => self::SOLD_AT]);
        DB::table('car_sale_attributions')->insert($t + ['company_id' => $c, 'car_id' => $car->id, 'car_sale_id' => $saleId, 'sold_at' => self::SOLD_AT,
            'sale_price' => 15000, 'attributed_platform' => 'meta', 'attributed_campaign_id' => 'C1', 'attributed_adset_id' => 'S1', 'attributed_ad_id' => 'A1',
            'attribution_model' => 'last_touch_recent_window', 'attribution_window_days' => 7, 'match_type' => 'direct_ad', 'confidence_score' => 80]);
        DB::table('car_sales_learning')->insert($t + ['company_id' => $c, 'car_id' => $car->id, 'sold_at' => self::SOLD_AT, 'sale_price' => 15000,
            'campaign_ids' => json_encode(['C1']), 'ad_ids' => json_encode(['A1']), 'adset_ids' => json_encode(['S1'])]);
        DB::table('car_ad_attributions')->insert($t + ['company_id' => $c, 'car_id' => $car->id, 'source' => 'xplendor_js', 'platform' => 'meta', 'campaign_id' => 'C1', 'ad_id' => 'A1', 'visitor_id' => 'v-' . $c]);
    }

    /** Linhas com dados da Meta que existem para a empresa (o que o purge tem de levar a zero). */
    private function metaFootprint(int $companyId): array
    {
        $count = fn (string $table, array $where = []) => DB::table($table)->where('company_id', $companyId)->where($where)->count();

        return [
            'meta_account_insights_daily' => $count('meta_account_insights_daily'),
            'meta_ad_insights_daily' => $count('meta_ad_insights_daily'),
            'meta_ads' => $count('meta_ads'),
            'meta_ad_car_spend_daily' => $count('meta_ad_car_spend_daily'),
            'meta_custom_audiences' => $count('meta_custom_audiences'),
            'meta_audience_insights' => $count('meta_audience_insights'),
            'campaign_car_metrics_daily' => $count('campaign_car_metrics_daily'),
            'car_ad_campaigns' => $count('car_ad_campaigns'),
            'paid_meta_metrics' => $count('car_performance_metrics', ['data_source' => 'meta_ads']),
            'meta_expenses' => $count('expenses', ['source' => 'meta_ads']),
            'attributed_ids' => DB::table('car_sale_attributions')->where('company_id', $companyId)->whereNotNull('attributed_campaign_id')->count(),
            'learning_ids' => DB::table('car_sales_learning')->where('company_id', $companyId)->whereNotNull('campaign_ids')->count(),
            'integration' => $count('company_integrations', ['platform' => 'meta']),
        ];
    }

    private function allOnes(): array
    {
        return array_fill_keys(array_keys($this->metaFootprint($this->companyA->id)), 1);
    }

    // ── 1. Retirada da autorização ───────────────────────────────────────────

    public function test_disconnect_revokes_permission_with_the_token_before_erasing_it_and_keeps_history(): void
    {
        Http::fake([self::PERMISSIONS_URL => Http::response(['success' => true])]);

        $this->actingAs($this->adminA, 'sanctum')->deleteJson($this->url($this->companyA))
            ->assertOk()
            ->assertJsonPath('data.permissions_revoked', true)
            ->assertJsonPath('data.purged', false);

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE'
            && str_contains($r->url(), '/me/permissions')
            && str_contains($r->url(), 'access_token=tok-A'));
        Http::assertSentCount(1);

        $integration = CompanyIntegration::where('company_id', $this->companyA->id)->sole();
        $this->assertSame('revoked', $integration->status);
        $this->assertSame('', $integration->access_token);
        // Histórico mantido por omissão.
        $this->assertSame($this->allOnes(), $this->metaFootprint($this->companyA->id));
    }

    public function test_disconnect_with_expired_token_continues_and_logs_without_the_token(): void
    {
        Http::fake([self::PERMISSIONS_URL => Http::response(['error' => ['message' => 'Error validating access token: Session has expired', 'code' => 190]], 400)]);
        Log::spy();

        $this->actingAs($this->adminA, 'sanctum')->deleteJson($this->url($this->companyA))
            ->assertOk()
            ->assertJsonPath('data.permissions_revoked', false);

        $this->assertSame('revoked', CompanyIntegration::where('company_id', $this->companyA->id)->value('status'));
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => str_contains($message, 'retirar a autorização')
            && $context['company_id'] === $this->companyA->id
            && str_contains($context['error'], 'Session has expired')
            && ! str_contains(json_encode($context), 'tok-A'))->once();
    }

    // ── 2. Desligar e apagar ─────────────────────────────────────────────────

    public function test_purge_requires_explicit_confirmation(): void
    {
        Http::fake();

        $this->actingAs($this->adminA, 'sanctum')->deleteJson($this->url($this->companyA), ['purge' => true])->assertStatus(422);
        $this->actingAs($this->adminA, 'sanctum')->deleteJson($this->url($this->companyA), ['purge' => true, 'confirmation' => 'apagar'])->assertStatus(422);

        Http::assertNothingSent();   // nem chega a retirar a autorização
        $this->assertSame('tok-A', CompanyIntegration::where('company_id', $this->companyA->id)->sole()->access_token);
        $this->assertSame($this->allOnes(), $this->metaFootprint($this->companyA->id));
    }

    public function test_purge_deletes_all_meta_data_of_own_company_and_keeps_sales(): void
    {
        Http::fake([self::PERMISSIONS_URL => Http::response(['success' => true])]);
        $before = $this->metaFootprint($this->companyB->id);

        $this->actingAs($this->adminA, 'sanctum')
            ->deleteJson($this->url($this->companyA), ['purge' => true, 'confirmation' => 'APAGAR'])
            ->assertOk()
            ->assertJsonPath('data.permissions_revoked', true)
            ->assertJsonPath('data.purged', true)
            ->assertJsonPath('data.deleted.meta_ads', 1)
            ->assertJsonPath('data.deleted.car_sale_attributions', 1);

        $this->assertSame(array_fill_keys(array_keys($before), 0), $this->metaFootprint($this->companyA->id));

        // Vendas mantidas; atribuição passa a "sem campanha", com a data da venda intacta.
        $this->assertSame(1, DB::table('car_sales')->where('company_id', $this->companyA->id)->count());
        $attribution = DB::table('car_sale_attributions')->where('company_id', $this->companyA->id)->sole();
        $this->assertNull($attribution->attributed_platform);
        $this->assertNull($attribution->attributed_ad_id);
        $this->assertSame('none', $attribution->match_type);
        $this->assertSame(self::SOLD_AT, substr((string) $attribution->sold_at, 0, 19));
        $this->assertSame(self::SOLD_AT, substr((string) DB::table('car_sales_learning')->where('company_id', $this->companyA->id)->value('sold_at'), 0, 19));

        // Fica o que não vem da Meta: métrica paga manual, despesa manual, visitas do site.
        $this->assertSame(1, DB::table('car_performance_metrics')->where('company_id', $this->companyA->id)->where('data_source', 'manual')->count());
        $this->assertSame(1, DB::table('expenses')->where('company_id', $this->companyA->id)->where('source', 'manual')->count());
        $this->assertSame(1, DB::table('car_ad_attributions')->where('company_id', $this->companyA->id)->count());

        // A outra empresa fica intacta.
        $this->assertSame($before, $this->metaFootprint($this->companyB->id));
        $this->assertSame('tok-B', CompanyIntegration::where('company_id', $this->companyB->id)->sole()->access_token);
    }

    public function test_purge_after_disconnecting_with_history_does_not_call_meta(): void
    {
        DB::table('company_integrations')->where('company_id', $this->companyA->id)->update(['status' => 'revoked', 'access_token' => '']);
        Http::fake();

        $this->actingAs($this->adminA, 'sanctum')
            ->deleteJson($this->url($this->companyA), ['purge' => true, 'confirmation' => 'APAGAR'])
            ->assertOk()
            ->assertJsonPath('data.permissions_revoked', false);

        Http::assertNothingSent();
        $this->assertSame(0, array_sum($this->metaFootprint($this->companyA->id)));
    }

    public function test_company_a_cannot_disconnect_or_purge_company_b(): void
    {
        Http::fake();
        $before = $this->metaFootprint($this->companyB->id);

        $this->actingAs($this->adminA, 'sanctum')
            ->deleteJson($this->url($this->companyB), ['purge' => true, 'confirmation' => 'APAGAR'])
            ->assertStatus(403);

        Http::assertNothingSent();
        $this->assertSame($before, $this->metaFootprint($this->companyB->id));
        $this->assertSame('active', CompanyIntegration::where('company_id', $this->companyB->id)->value('status'));
    }

    // ── 3. Scope do OAuth ────────────────────────────────────────────────────

    public function test_oauth_url_requests_only_ads_read(): void
    {
        $url = $this->actingAs($this->adminA, 'sanctum')
            ->getJson($this->url($this->companyA) . '/oauth-url')
            ->assertOk()
            ->json('data.url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('ads_read', $query['scope']);
    }
}
