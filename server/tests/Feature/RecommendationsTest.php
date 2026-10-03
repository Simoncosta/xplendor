<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SyncMetaAccountInsightsJob;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\CompanyRecommendationSetting;
use App\Models\MetaCustomAudience;
use App\Models\User;
use App\Recommendations\Contracts\RecommendationRule;
use App\Recommendations\Recommendation;
use App\Recommendations\RecommendationContext;
use App\Recommendations\RecommendationEngine;
use App\Recommendations\RuleResult;
use App\Recommendations\Rules\StaleCustomerAudienceRule;
use App\Services\MetaAccountInsightsService;
use App\Services\MetaCustomAudiencesService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * XPLENDOR — Motor de recomendações (regras explicáveis) + Regra 1 "público de
 * clientes desatualizado" + fotografia dos públicos Meta + tenancy.
 */
class RecommendationsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;
    private int $planId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-04 12:00:00'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-04 12:00:00'));

        $this->planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 9, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = $this->makeCompany('500005001', 'Yuko');
        $this->user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function makeCompany(string $nipc, string $name): Company
    {
        return Company::create(['nipc' => $nipc, 'fiscal_name' => $name, 'plan_id' => $this->planId, 'subscription_status' => 'active']);
    }

    private function meta(array $overrides = []): CompanyIntegration
    {
        return CompanyIntegration::create(array_merge([
            'company_id' => $this->company->id, 'platform' => 'meta', 'access_token' => 'tok', 'account_id' => '123',
            'token_expires_at' => now()->addDays(30), 'status' => 'active', 'audiences_sync_status' => 'ok',
        ], $overrides));
    }

    private function audience(string $name, ?string $updated, string $subtype = 'CUSTOM', ?int $lower = 0, ?int $upper = 1000): void
    {
        MetaCustomAudience::create([
            'company_id' => $this->company->id, 'account_id' => '123', 'audience_id' => 'a-' . md5($name),
            'name' => $name, 'subtype' => $subtype, 'approximate_count_lower_bound' => $lower,
            'approximate_count_upper_bound' => $upper, 'time_content_updated' => $updated, 'fetched_at' => now(),
        ]);
    }

    private function engine(): RecommendationEngine
    {
        return app(RecommendationEngine::class);
    }

    // ── Motor ─────────────────────────────────────────────────────────────────

    public function test_engine_orders_by_priority_and_filters_by_vertical(): void
    {
        $engine = new RecommendationEngine([
            new FakeRule('low', ['restaurant'], 20),
            new FakeRule('high', ['restaurant'], 90),
            new FakeRule('mid', ['restaurant'], 55),
            new FakeRule('cars_only', ['automotive'], 99),
        ]);

        $out = $engine->forCompany($this->company, 'restaurant');

        $this->assertSame(['high', 'mid', 'low'], array_column($out['recommendations'], 'rule_key'));
        $this->assertSame(['high', 'medium', 'low'], array_column($out['recommendations'], 'level'));
    }

    public function test_engine_respects_company_settings_enabled_and_params(): void
    {
        $rule = new FakeRule('configurable', ['restaurant'], 50);
        $engine = new RecommendationEngine([$rule, new FakeRule('off', ['restaurant'], 80)]);

        CompanyRecommendationSetting::create(['company_id' => $this->company->id, 'rule_key' => 'off', 'enabled' => false]);
        CompanyRecommendationSetting::create(['company_id' => $this->company->id, 'rule_key' => 'configurable', 'params' => ['threshold' => 7]]);

        $out = $engine->forCompany($this->company, 'restaurant');

        $this->assertSame(['configurable'], array_column($out['recommendations'], 'rule_key')); // 'off' desligada
        $this->assertSame(['threshold' => 7], $rule->lastParams);                               // parâmetros da empresa
    }

    public function test_a_failing_rule_does_not_break_the_others(): void
    {
        $engine = new RecommendationEngine([new FakeRule('boom', ['restaurant'], 50, throws: true), new FakeRule('ok', ['restaurant'], 40)]);

        $this->assertSame(['ok'], array_column($engine->forCompany($this->company, 'restaurant')['recommendations'], 'rule_key'));
    }

    public function test_stale_audience_rule_is_registered(): void
    {
        $this->assertContains(StaleCustomerAudienceRule::KEY, array_map(fn ($r) => $r->key(), $this->engine()->rules()));
    }

    // ── Regra 1 ───────────────────────────────────────────────────────────────

    public function test_rule_threshold_default_45_days(): void
    {
        $this->meta();
        $this->audience('Recente', '2026-08-21 10:00:00');   // 44 dias → não
        $this->audience('Antigo', '2026-08-19 10:00:00');    // 46 dias → sim

        $recs = $this->engine()->forCompany($this->company, 'restaurant')['recommendations'];

        $this->assertCount(1, $recs);
        $this->assertSame(46, $recs[0]['evidence']['days_since_update']);
        $this->assertSame(45, $recs[0]['evidence']['max_days']);
    }

    public function test_rule_threshold_is_configurable_and_clamped_30_to_60(): void
    {
        $this->meta();
        $this->audience('A', '2026-08-31 10:00:00'); // 34 dias

        CompanyRecommendationSetting::create(['company_id' => $this->company->id, 'rule_key' => StaleCustomerAudienceRule::KEY, 'params' => ['max_days' => 30]]);
        $this->assertCount(1, $this->engine()->forCompany($this->company, 'restaurant')['recommendations']);

        // 10 dias pedidos → limitado a 30 (34 > 30 → continua a recomendar).
        CompanyRecommendationSetting::where('rule_key', StaleCustomerAudienceRule::KEY)->update(['params' => json_encode(['max_days' => 10])]);
        $this->assertSame(30, $this->engine()->forCompany($this->company, 'restaurant')['recommendations'][0]['evidence']['max_days']);

        // 90 pedidos → limitado a 60 (34 < 60 → sem recomendação).
        CompanyRecommendationSetting::where('rule_key', StaleCustomerAudienceRule::KEY)->update(['params' => json_encode(['max_days' => 90])]);
        $this->assertCount(0, $this->engine()->forCompany($this->company, 'restaurant')['recommendations']);
    }

    public function test_rule_only_considers_customer_lists(): void
    {
        $this->meta();
        $this->audience('Site', '2026-01-01 10:00:00', 'WEBSITE');
        $this->audience('Semelhante', '2026-01-01 10:00:00', 'LOOKALIKE');
        $this->audience('Sem data', null, 'CUSTOM');
        $this->audience('Lista', '2026-01-01 10:00:00', 'CUSTOM');

        $recs = $this->engine()->forCompany($this->company, 'restaurant')['recommendations'];

        $this->assertCount(1, $recs);
        $this->assertSame('Lista', $recs[0]['evidence']['audience_name']);
    }

    public function test_rule_priority_rises_with_staleness_and_size(): void
    {
        $this->meta();
        $this->audience('Pouco atrasado pequeno', '2026-08-10 10:00:00', 'CUSTOM', 0, 1000);      // 55 dias
        $this->audience('Muito atrasado pequeno', '2026-04-18 10:00:00', 'CUSTOM', 0, 1000);      // 169 dias
        $this->audience('Muito atrasado grande', '2026-04-18 10:00:00', 'CUSTOM', 150000, 200000);

        $recs = collect($this->engine()->forCompany($this->company, 'restaurant')['recommendations'])->keyBy(fn ($r) => $r['evidence']['audience_name']);

        $this->assertLessThan($recs['Muito atrasado pequeno']['priority'], $recs['Pouco atrasado pequeno']['priority']);
        $this->assertLessThan($recs['Muito atrasado grande']['priority'], $recs['Muito atrasado pequeno']['priority']);
        $this->assertSame(80, $recs['Muito atrasado pequeno']['priority']);   // 40 + 40 + 0
        $this->assertSame('high', $recs['Muito atrasado pequeno']['level']);
    }

    public function test_rule_text_and_action(): void
    {
        $this->meta();
        $this->audience('Covermanager_17-04', '2026-04-18 10:00:00');

        $r = $this->engine()->forCompany($this->company, 'restaurant')['recommendations'][0];

        $this->assertSame('Público de clientes desatualizado', $r['title']);
        $this->assertSame('O público «Covermanager_17-04» (lista de clientes, abaixo de 1 000 pessoas) não é atualizado há 169 dias. Os clientes que chegaram desde então não estão incluídos.', $r['why']);
        $this->assertSame('Atualizar no Gestor de Anúncios', $r['action']['label']);
        $this->assertSame('https://adsmanager.facebook.com/adsmanager/audiences?act=123', $r['action']['url']);
        $this->assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}]/u', $r['why']); // sem travessões
    }

    public function test_rule_shows_honest_notice_without_permission(): void
    {
        $this->meta(['audiences_sync_status' => 'no_permission']);
        $this->audience('Antigo', '2026-01-01 10:00:00'); // fotografia antiga não conta

        $out = $this->engine()->forCompany($this->company, 'restaurant');

        $this->assertSame([], $out['recommendations']);
        $this->assertSame('meta_audiences_no_permission', $out['notices'][0]['code']);
        $this->assertStringContainsString('Sem permissão para ler os públicos da Meta', $out['notices'][0]['message']);
    }

    public function test_rule_silent_without_meta(): void
    {
        $out = $this->engine()->forCompany($this->company, 'restaurant');

        $this->assertSame(['recommendations' => [], 'notices' => []], $out);
    }

    // ── Fotografia dos públicos (API simulada) ───────────────────────────────

    public function test_audiences_snapshot_is_stored(): void
    {
        $integration = $this->meta(['audiences_sync_status' => null]);
        Http::fake(['graph.facebook.com/*/customaudiences*' => Http::response(['data' => [
            ['id' => '111', 'name' => 'Clientes', 'subtype' => 'CUSTOM', 'approximate_count_lower_bound' => 1000,
                'approximate_count_upper_bound' => 1200, 'time_content_updated' => 1755000000,
                'delivery_status' => ['code' => 200, 'description' => 'This audience is ready for use.']],
            ['id' => '222', 'name' => 'Visitantes do site', 'subtype' => 'WEBSITE'],
        ]])]);

        $res = app(MetaCustomAudiencesService::class)->sync($integration);

        $this->assertSame('ok', $res['result']);
        $this->assertSame(2, MetaCustomAudience::count());
        $list = MetaCustomAudience::where('audience_id', '111')->first();
        $this->assertSame('CUSTOM', $list->subtype);
        $this->assertSame(1200, $list->approximate_count_upper_bound);
        $this->assertNotNull($list->time_content_updated);
        $this->assertSame(200, $list->delivery_status_code);
        $this->assertSame('ok', $integration->fresh()->audiences_sync_status);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/act_123/customaudiences') && str_contains(urldecode($r->url()), 'time_content_updated'));
    }

    public function test_permission_error_is_recorded_and_snapshot_kept(): void
    {
        $integration = $this->meta(['audiences_sync_status' => 'ok']);
        $this->audience('Antiga', '2026-01-01 10:00:00');
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 200, 'message' => '(#200) Requires ads_management permission']], 400)]);

        $res = app(MetaCustomAudiencesService::class)->sync($integration);

        $this->assertSame('no_permission', $res['result']);
        $this->assertSame('no_permission', $integration->fresh()->audiences_sync_status);
        $this->assertSame(1, MetaCustomAudience::count()); // não apaga a fotografia por causa de um erro
    }

    public function test_audiences_failure_never_breaks_the_insights_sync(): void
    {
        $integration = $this->meta(['insights_backfilled_at' => now()->subDay(), 'insights_backfill_months' => 13]);
        Http::fake([
            'graph.facebook.com/*/insights*' => Http::response(['data' => []]),
            'graph.facebook.com/*/customaudiences*' => Http::response(['error' => ['code' => 10, 'message' => 'No permission']], 400),
        ]);

        (new SyncMetaAccountInsightsJob($integration->id))->handle(app(MetaAccountInsightsService::class));

        $fresh = $integration->fresh();
        $this->assertSame('done', $fresh->insights_sync_status);       // insights OK
        $this->assertSame('no_permission', $fresh->audiences_sync_status); // públicos: estado honesto
    }

    // ── Endpoint + tenancy ───────────────────────────────────────────────────

    public function test_endpoint_returns_own_recommendations_and_blocks_other_company(): void
    {
        $this->meta();
        $this->audience('Covermanager_17-04', '2026-04-18 10:00:00');
        $other = $this->makeCompany('500005002', 'Outro');

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/companies/{$this->company->id}/recommendations?vertical=restaurant")
            ->assertStatus(200)
            ->assertJsonPath('data.recommendations.0.rule_key', StaleCustomerAudienceRule::KEY);

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/companies/{$other->id}/recommendations?vertical=restaurant")
            ->assertStatus(403);

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/companies/{$this->company->id}/recommendations?vertical=xpto")
            ->assertStatus(422);
    }
}

/** Regra falsa para testar o motor. */
class FakeRule implements RecommendationRule
{
    public array $lastParams = [];

    public function __construct(private string $k, private array $v, private int $priority, private bool $throws = false) {}

    public function key(): string { return $this->k; }
    public function verticals(): array { return $this->v; }
    public function defaultParams(): array { return []; }
    public function normalizeParams(array $params): array { return $params; }

    public function evaluate(RecommendationContext $context, array $params): RuleResult
    {
        if ($this->throws) {
            throw new \RuntimeException('boom');
        }
        $this->lastParams = $params;

        return new RuleResult([new Recommendation($this->k, $this->priority, 'T', 'W', [], ['label' => 'L', 'url' => '#'], $context->now)]);
    }
}
