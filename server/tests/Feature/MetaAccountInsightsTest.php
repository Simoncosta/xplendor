<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DispatchMetaAccountInsightsSyncJob;
use App\Jobs\SyncMetaAccountInsightsJob;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\MetaAccountInsightDaily;
use App\Models\User;
use App\Services\MetaAccountInsightsService;
use App\Services\MetaAdsService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * XPLENDOR — Meta Ads: ingestão AO NÍVEL DA CONTA (todas as verticais).
 *
 * Cobre: a chamada act_{id}/insights (forma + parsing + paginação), o backfill de
 * 90 dias, o diário dos últimos 3 dias (+ hoje) com substituição da janela, os
 * erros (token 190 / genéricos), os disparos (definir conta / OAuth / despachante)
 * e o overview a ler a tabela nova com os ESTADOS honestos. A empresa de teste é de
 * RESTAURAÇÃO (sem carros) — o caso que ficava a zero.
 */
class MetaAccountInsightsTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-10-03';

    private Company $company;
    private User $user;
    private int $planId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::TODAY . ' 12:00:00'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::TODAY . ' 12:00:00'));

        $this->planId = DB::table('plans')->insertGetId([
            'name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->company = $this->makeCompany('500002001', 'Restaurante Sabor');
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

    private function row(string $date, string $campaignId, float $spend, int $impr, int $clicks, ?string $name = null, string $account = '123'): void
    {
        MetaAccountInsightDaily::create([
            'company_id' => $this->company->id, 'account_id' => $account, 'date' => $date,
            'campaign_id' => $campaignId, 'campaign_name' => $name ?? ('Camp ' . $campaignId),
            'spend' => $spend, 'impressions' => $impr, 'clicks' => $clicks,
        ]);
    }

    private function graphRow(string $date, string $campaignId, string $spend, string $impr, string $clicks, string $name = 'Camp'): array
    {
        return ['date_start' => $date, 'date_stop' => $date, 'campaign_id' => $campaignId, 'campaign_name' => $name,
            'spend' => $spend, 'impressions' => $impr, 'clicks' => $clicks];
    }

    private function timeRangeOf(HttpRequest $req): array
    {
        parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);

        return json_decode($q['time_range'] ?? '{}', true) ?: [];
    }

    private function overview(int $days = 28)
    {
        return $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/companies/' . $this->company->id . '/analytics/meta/overview?days=' . $days)
            ->assertStatus(200);
    }

    // ── Graph API: forma da chamada, parsing, paginação ──────────────────────

    public function test_graph_call_shape_parsing_and_pagination(): void
    {
        Http::fake([
            'graph.facebook.com/v25.0/act_123/insights*' => Http::sequence()
                ->push(['data' => [
                    $this->graphRow('2026-09-01', 'c1', '10.50', '1000', '20', 'Menu Outono'),
                    $this->graphRow('2026-09-01', 'c2', '5', '400', '8'),
                ], 'paging' => ['next' => 'https://graph.facebook.com/v25.0/act_123/insights?access_token=tok&level=campaign&after=CURSOR']])
                ->push(['data' => [$this->graphRow('2026-09-02', 'c1', '7.25', '700', '14', 'Menu Outono')]]),
        ]);

        $res = app(MetaAdsService::class)->getAccountCampaignInsightsDaily('tok', 'act_123', '2026-09-01', '2026-09-02');

        $this->assertTrue($res['ok']);
        $this->assertCount(3, $res['rows']);
        $this->assertSame(['date' => '2026-09-01', 'campaign_id' => 'c1', 'campaign_name' => 'Menu Outono', 'spend' => 10.5, 'impressions' => 1000, 'clicks' => 20], $res['rows'][0]);
        $this->assertSame(7.25, $res['rows'][2]['spend']);

        Http::assertSentCount(2); // seguiu paging.next
        // REGRESSÃO (crítico da revisão): a 2.ª página TEM de levar o token e o
        // cursor `after` — pedir o paging.next com query vazia apagava-os (erro 104).
        Http::assertSent(function (HttpRequest $req) {
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);

            return str_contains($req->url(), '/act_123/insights')
                && ($q['after'] ?? null) === 'CURSOR'
                && ($q['access_token'] ?? null) === 'tok';
        });
        Http::assertSent(function (HttpRequest $req) {
            if (! str_contains($req->url(), '/act_123/insights') || str_contains($req->url(), 'after=')) {
                return false;
            }
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);

            return ($q['level'] ?? null) === 'campaign'
                && (string) ($q['time_increment'] ?? '') === '1'
                && str_contains($q['fields'] ?? '', 'campaign_name')
                && str_contains($q['fields'] ?? '', 'spend')
                && $this->timeRangeOf($req) === ['since' => '2026-09-01', 'until' => '2026-09-02']
                && ! str_contains($req->url(), 'act_act_'); // prefixo normalizado
        });
    }

    // ── Backfill de 90 dias ───────────────────────────────────────────────────

    public function test_backfill_fetches_90_days_writes_rows_and_marks_state(): void
    {
        $integration = $this->integration(['insights_sync_status' => 'pending']);
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => [
            $this->graphRow('2026-07-10', 'c1', '30', '3000', '60', 'Verão'),
            $this->graphRow('2026-10-02', 'c2', '12', '900', '18', 'Outono'),
        ]])]);

        (new SyncMetaAccountInsightsJob($integration->id, MetaAccountInsightsService::MODE_BACKFILL))
            ->handle(app(MetaAccountInsightsService::class));

        Http::assertSent(fn (HttpRequest $r) => $this->timeRangeOf($r) === ['since' => '2026-07-06', 'until' => self::TODAY]); // 90 dias
        $this->assertSame(2, MetaAccountInsightDaily::where('company_id', $this->company->id)->count());

        $integration->refresh();
        $this->assertSame('done', $integration->insights_sync_status);
        $this->assertNotNull($integration->insights_backfilled_at);
        $this->assertNotNull($integration->last_synced_at);
        $this->assertNotNull($integration->insights_last_run_at);
        $this->assertNull($integration->insights_error);
        $this->assertNotNull($integration->insights_synced_at);
        $this->assertSame(self::TODAY, $integration->insights_synced_until->toDateString());
    }

    // ── Diário: últimos 3 dias + hoje, substituindo a janela ──────────────────

    public function test_daily_fetches_last_3_days_and_replaces_window(): void
    {
        $integration = $this->integration(['insights_backfilled_at' => now()->subDay(), 'insights_sync_status' => 'done']);
        $this->row('2026-10-02', 'stale', 99, 999, 99);   // dentro da janela → substituída
        $this->row('2026-09-20', 'keep', 50, 500, 10);    // fora da janela → intacta

        Http::fake(['graph.facebook.com/*' => Http::response(['data' => [
            $this->graphRow('2026-10-02', 'fresh', '8', '800', '16'),
        ]])]);

        (new SyncMetaAccountInsightsJob($integration->id, MetaAccountInsightsService::MODE_DAILY))
            ->handle(app(MetaAccountInsightsService::class));

        Http::assertSent(fn (HttpRequest $r) => $this->timeRangeOf($r) === ['since' => '2026-09-30', 'until' => self::TODAY]);
        $this->assertDatabaseMissing('meta_account_insights_daily', ['campaign_id' => 'stale']);
        $this->assertDatabaseHas('meta_account_insights_daily', ['campaign_id' => 'keep']);
        $this->assertDatabaseHas('meta_account_insights_daily', ['campaign_id' => 'fresh', 'date' => '2026-10-02']);
    }

    public function test_daily_upgrades_to_backfill_when_never_backfilled(): void
    {
        $integration = $this->integration(); // insights_backfilled_at null
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => []])]);

        (new SyncMetaAccountInsightsJob($integration->id, MetaAccountInsightsService::MODE_DAILY))
            ->handle(app(MetaAccountInsightsService::class));

        Http::assertSent(fn (HttpRequest $r) => ($this->timeRangeOf($r)['since'] ?? null) === '2026-07-06');
        $integration->refresh();
        $this->assertNotNull($integration->insights_backfilled_at); // 0 linhas também é sucesso
        $this->assertSame('done', $integration->insights_sync_status);
    }

    // ── Erros ─────────────────────────────────────────────────────────────────

    public function test_token_invalid_190_marks_expired_and_keeps_rows(): void
    {
        $integration = $this->integration(['insights_backfilled_at' => now()->subDay()]);
        $this->row('2026-10-02', 'c1', 10, 100, 5);
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 190, 'message' => 'Session has expired']], 400)]);

        (new SyncMetaAccountInsightsJob($integration->id))->handle(app(MetaAccountInsightsService::class));

        $integration->refresh();
        $this->assertSame('expired', $integration->status);
        $this->assertSame('token_expired', $integration->insights_sync_status);
        $this->assertSame(1, MetaAccountInsightDaily::count()); // dados antigos não se apagam
    }

    public function test_generic_graph_error_marks_failed_with_message(): void
    {
        $integration = $this->integration();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 100, 'message' => 'Invalid ad account']], 400)]);

        (new SyncMetaAccountInsightsJob($integration->id))->handle(app(MetaAccountInsightsService::class));

        $integration->refresh();
        $this->assertSame('failed', $integration->insights_sync_status);
        $this->assertSame('Invalid ad account', $integration->insights_error);
        $this->assertSame('active', $integration->status);
        $this->assertNotNull($integration->insights_last_run_at);
    }

    public function test_no_account_records_needs_account_without_calling_graph(): void
    {
        $integration = $this->integration(['account_id' => null]);
        Http::fake();

        (new SyncMetaAccountInsightsJob($integration->id))->handle(app(MetaAccountInsightsService::class));

        Http::assertNothingSent();
        $integration->refresh();
        $this->assertSame('needs_account', $integration->insights_sync_status);
        $this->assertNotNull($integration->insights_last_run_at);
    }

    public function test_expired_token_by_date_skips_graph(): void
    {
        $integration = $this->integration(['token_expires_at' => now()->subDay()]);
        Http::fake();

        (new SyncMetaAccountInsightsJob($integration->id))->handle(app(MetaAccountInsightsService::class));

        Http::assertNothingSent();
        $this->assertSame('token_expired', $integration->fresh()->insights_sync_status);
        $this->assertSame('expired', $integration->fresh()->status);
    }

    public function test_pagination_truncated_at_page_limit_is_not_ok_and_writes_nothing(): void
    {
        $integration = $this->integration(['insights_backfilled_at' => now()->subDay()]);
        $this->row('2026-10-02', 'keep', 10, 100, 5);
        // A Meta devolve SEMPRE um next → bate no limite de páginas.
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => [$this->graphRow('2026-10-02', 'c1', '1', '1', '1')],
            'paging' => ['next' => 'https://graph.facebook.com/v25.0/act_123/insights?access_token=tok&after=X']])]);

        (new SyncMetaAccountInsightsJob($integration->id))->handle(app(MetaAccountInsightsService::class));

        $this->assertSame('failed', $integration->fresh()->insights_sync_status);
        $this->assertDatabaseHas('meta_account_insights_daily', ['campaign_id' => 'keep']); // janela NÃO substituída por dados parciais
        $this->assertDatabaseMissing('meta_account_insights_daily', ['campaign_id' => 'c1']);
    }

    public function test_meta_throttling_is_retryable_not_failed(): void
    {
        $integration = $this->integration(['insights_backfilled_at' => now()->subDay()]);
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 17, 'message' => 'User request limit reached']], 400)]);

        $result = app(MetaAccountInsightsService::class)->sync($integration);

        $this->assertSame('retryable', $result['result']);
        $this->assertSame('pending', $integration->fresh()->insights_sync_status); // não mostra "falhou"
        $this->assertSame('active', $integration->fresh()->status);
    }

    public function test_daily_recovers_missed_days_from_watermark(): void
    {
        // Último sync com sucesso parou há 10 dias → o diário recua até lá (sem buracos).
        $integration = $this->integration([
            'insights_backfilled_at' => now()->subDays(20),
            'insights_synced_until'  => '2026-09-23',
        ]);
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => []])]);

        (new SyncMetaAccountInsightsJob($integration->id))->handle(app(MetaAccountInsightsService::class));

        Http::assertSent(fn (HttpRequest $r) => $this->timeRangeOf($r) === ['since' => '2026-09-24', 'until' => self::TODAY]);
    }

    public function test_account_changed_mid_sync_discards_old_account_result(): void
    {
        $integration = $this->integration(); // conta 123, 1.º backfill
        Http::fake(function () use ($integration) {
            // O utilizador corrige a conta enquanto a Meta responde.
            CompanyIntegration::whereKey($integration->id)->update(['account_id' => '456']);

            return Http::response(['data' => [$this->graphRow('2026-10-02', 'c1', '5', '50', '5')]]);
        });

        $result = app(MetaAccountInsightsService::class)->sync($integration);

        $this->assertSame('account_changed', $result['result']);
        $this->assertSame(0, MetaAccountInsightDaily::count());               // nada órfão da conta antiga
        $this->assertNull($integration->fresh()->insights_backfilled_at);     // backfill NÃO dado como feito
    }

    public function test_job_exhausted_attempts_marks_pending_not_failed(): void
    {
        $integration = $this->integration();
        (new SyncMetaAccountInsightsJob($integration->id))
            ->failed(new \Illuminate\Queue\MaxAttemptsExceededException('exceeded'));

        $this->assertSame('pending', $integration->fresh()->insights_sync_status);
    }

    public function test_deploy_command_queues_backfill_for_existing_integrations(): void
    {
        Queue::fake();
        $pending = $this->integration();                                                          // nunca fez backfill
        $done = $this->integration(['insights_backfilled_at' => now()], $this->makeCompany('500002004', 'Já feito'));
        $noAccount = $this->integration(['account_id' => null], $this->makeCompany('500002005', 'Sem conta'));

        $this->artisan('meta:backfill-account-insights')->assertSuccessful();

        Queue::assertPushed(SyncMetaAccountInsightsJob::class, fn ($j) => $j->integrationId === $pending->id && $j->mode === 'backfill');
        Queue::assertNotPushed(SyncMetaAccountInsightsJob::class, fn ($j) => $j->integrationId === $done->id);
        $this->assertSame('needs_account', $noAccount->fresh()->insights_sync_status);
    }

    // ── Disparos ──────────────────────────────────────────────────────────────

    public function test_setting_account_dispatches_backfill_and_wipes_old_account_rows(): void
    {
        Queue::fake();
        $integration = $this->integration(['account_id' => 'OLD', 'insights_backfilled_at' => now()->subDays(5)]);
        $this->row('2026-10-01', 'c1', 10, 100, 5, null, 'OLD');

        $this->actingAs($this->user, 'sanctum')
            ->patchJson('/api/v1/companies/' . $this->company->id . '/integrations/meta/account', ['account_id' => 'act_NEW'])
            ->assertStatus(200);

        $integration->refresh();
        $this->assertSame('NEW', $integration->account_id);
        $this->assertSame('pending', $integration->insights_sync_status);
        $this->assertNull($integration->insights_backfilled_at);
        $this->assertSame(0, MetaAccountInsightDaily::where('account_id', 'OLD')->count());
        Queue::assertPushed(SyncMetaAccountInsightsJob::class, fn ($job) =>
            $job->integrationId === $integration->id && $job->mode === MetaAccountInsightsService::MODE_BACKFILL);
    }

    public function test_oauth_callback_dispatches_backfill_when_account_exists(): void
    {
        Queue::fake();
        config([
            'services.meta.app_id'       => 'app-id',
            'services.meta.app_secret'   => 'app-secret',
            'services.meta.redirect_uri' => 'https://xplendor.test/api/oauth/meta/callback',
        ]);
        $integration = $this->integration(['status' => 'expired', 'token_expires_at' => now()->subDay()]); // reconexão
        Cache::put('meta_oauth_state:NONCE1', $this->company->id, now()->addMinutes(5));
        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'new-token']),
            'graph.facebook.com/*debug_token*'          => Http::response(['data' => ['expires_at' => now()->addDays(60)->timestamp]]),
        ]);

        $this->get('/api/oauth/meta/callback?state=NONCE1&code=CODE1')->assertRedirect();

        $integration->refresh();
        $this->assertSame('active', $integration->status);
        Queue::assertPushed(SyncMetaAccountInsightsJob::class, fn ($job) =>
            $job->integrationId === $integration->id && $job->mode === MetaAccountInsightsService::MODE_BACKFILL);
    }

    public function test_daily_dispatcher_records_state_for_every_integration(): void
    {
        Queue::fake();
        $ok = $this->integration();
        $noAccount = $this->integration(['account_id' => null], $this->makeCompany('500002002', 'Sem Conta'));
        $expired = $this->integration(['token_expires_at' => now()->subDay()], $this->makeCompany('500002003', 'Expirado'));

        (new DispatchMetaAccountInsightsSyncJob())->handle();

        Queue::assertPushed(SyncMetaAccountInsightsJob::class, fn ($job) => $job->integrationId === $ok->id);
        Queue::assertPushed(SyncMetaAccountInsightsJob::class, 1);
        $this->assertSame('needs_account', $noAccount->fresh()->insights_sync_status);
        $this->assertNotNull($noAccount->fresh()->insights_last_run_at);
        $this->assertSame('token_expired', $expired->fresh()->insights_sync_status);
        $this->assertNotNull($expired->fresh()->insights_last_run_at);
    }

    // ── Overview a ler a tabela nova + estados honestos ──────────────────────

    public function test_overview_reads_account_level_data_for_non_car_company(): void
    {
        $this->integration(['insights_backfilled_at' => now(), 'insights_sync_status' => 'done']);
        $this->row('2026-09-20', 'c1', 30, 3000, 60, 'Menu Outono');
        $this->row('2026-09-21', 'c1', 20, 2000, 40, 'Menu Outono');
        $this->row('2026-09-22', 'c2', 10, 1000, 20, 'Brunch');
        $this->row('2026-09-22', 'cX', 999, 9, 9, 'Outra conta', '999'); // conta antiga: ignorada

        $res = $this->overview();

        $res->assertJsonPath('data.state', 'ok')
            ->assertJsonPath('data.source', 'account')
            ->assertJsonPath('data.connected', true)
            ->assertJsonPath('data.by_campaign.0.campaign_name', 'Menu Outono');
        $this->assertEquals(60, $res->json('data.overview.spend'));
        $this->assertEquals(6000, $res->json('data.overview.impressions'));
        $this->assertEquals(120, $res->json('data.overview.clicks'));
        $this->assertEquals(2, $res->json('data.overview.ctr'));   // 120/6000*100
        $this->assertEquals(0.5, $res->json('data.overview.cpc')); // 60/120
        $this->assertCount(3, $res->json('data.trend'));
    }

    public function test_state_token_expired_asks_to_reconnect(): void
    {
        $this->integration(['status' => 'expired', 'insights_backfilled_at' => now()]);
        $this->overview()->assertJsonPath('data.state', 'token_expired');
    }

    public function test_state_needs_account(): void
    {
        $this->integration(['account_id' => null]);
        $this->overview()->assertJsonPath('data.state', 'needs_account')->assertJsonPath('data.source', 'none');
    }

    public function test_state_syncing_first(): void
    {
        $this->integration(['insights_sync_status' => 'pending']);
        $this->overview()->assertJsonPath('data.state', 'syncing_first')->assertJsonPath('data.source', 'none');
    }

    public function test_state_sync_failed_exposes_error(): void
    {
        $this->integration(['insights_sync_status' => 'failed', 'insights_error' => 'Invalid ad account']);
        $this->overview()
            ->assertJsonPath('data.state', 'sync_failed')
            ->assertJsonPath('data.sync.error', 'Invalid ad account');
    }

    public function test_state_no_spend_is_real_zero(): void
    {
        $this->integration(['insights_backfilled_at' => now(), 'insights_sync_status' => 'done']);
        $this->row('2026-06-01', 'c1', 40, 400, 4); // fora do período (28 dias)

        $this->overview()->assertJsonPath('data.state', 'no_spend')->assertJsonPath('data.source', 'account');
        // …mas com 180 dias o gasto aparece (zero REAL do período, não bug).
        $this->assertEquals(40, $this->overview(180)->json('data.overview.spend'));
    }

    public function test_failing_daily_after_backfill_is_sync_failed_not_no_spend(): void
    {
        // Backfill feito, mas o diário está a falhar e não há linhas no período:
        // NÃO pode dizer "sem gasto" (seria mentir) — é sync_failed, com o erro.
        $this->integration(['insights_backfilled_at' => now()->subDays(5), 'insights_sync_status' => 'failed', 'insights_error' => 'Permissions error']);

        $this->overview()
            ->assertJsonPath('data.state', 'sync_failed')
            ->assertJsonPath('data.source', 'account')
            ->assertJsonPath('data.sync.error', 'Permissions error');
    }

    public function test_not_connected_when_revoked(): void
    {
        $this->integration(['status' => 'revoked']);
        $this->overview()->assertJsonPath('data.state', 'not_connected')->assertJsonPath('data.connected', false);
    }
}
