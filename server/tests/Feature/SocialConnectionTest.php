<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ReadSocialFollowersJob;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\ImpersonationSession;
use App\Models\SocialConnection;
use App\Models\SocialConnectionAccount;
use App\Models\SocialFollowerSnapshot;
use App\Models\User;
use App\Services\Social\FollowerSnapshotService;
use App\Services\Social\SocialConnectionService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Parte C: ligação das redes sociais (Instagram e Facebook), separada da dos anúncios.
 * A Graph API é sempre simulada. Fluxo OAuth com nonce, escolha das contas, leitura
 * diária dos seguidores, estados honestos, desligar só as permissões das redes,
 * tenancy e impersonation.
 */
class SocialConnectionTest extends TestCase
{
    use RefreshDatabase;

    private const USER_TOKEN = 'EAAuser-long-token-123';
    private const PAGE_TOKEN = 'EAApage-token-777';
    private const PAGE_TOKEN_2 = 'EAApage-token-888';

    private Company $a;
    private Company $b;
    private User $adminA;
    private User $userA;
    private User $adminB;
    private User $root;

    /** Respostas simuladas da Graph API, por "chave" (ver fakeGraph()). */
    private array $graph = [];

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-05 10:00:00', 'Europe/Lisbon'));
        config([
            'services.meta.app_id' => '111', 'services.meta.app_secret' => 'segredo',
            'services.meta.redirect_uri' => 'https://dev.exemplo.pt/api/oauth/meta/callback',
            'services.meta.social_redirect_uri' => null,
        ]);

        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->a = Company::create(['nipc' => '500040001', 'fiscal_name' => 'Quebom Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->b = Company::create(['nipc' => '500040002', 'fiscal_name' => 'Outra Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $x = Company::create(['nipc' => '500040003', 'fiscal_name' => 'XPLENDOR', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->adminA = User::factory()->create(['company_id' => $this->a->id, 'role' => 'admin']);
        $this->userA = User::factory()->create(['company_id' => $this->a->id, 'role' => 'user']);
        $this->adminB = User::factory()->create(['company_id' => $this->b->id, 'role' => 'admin']);
        $this->root = User::factory()->create(['company_id' => $x->id, 'role' => 'root']);

        $this->graph = [
            'scopes' => ['pages_show_list', 'pages_read_engagement', 'instagram_basic', 'public_profile'],
            'accounts' => ['data' => [
                ['id' => '1001', 'name' => 'Quebom', 'access_token' => self::PAGE_TOKEN, 'instagram_business_account' => ['id' => '1784001', 'username' => 'quebom', 'name' => 'Quebom']],
                ['id' => '1002', 'name' => 'Quebom Porto', 'access_token' => self::PAGE_TOKEN_2],
            ]],
            'page' => [200, ['followers_count' => 1250, 'id' => '1001']],
            'page2' => [200, ['followers_count' => 310, 'id' => '1002']],
            'ig' => [200, ['followers_count' => 4200, 'follows_count' => 180, 'media_count' => 96, 'id' => '1784001']],
            'permissions' => ['data' => [
                ['permission' => 'pages_show_list', 'status' => 'granted'],
                ['permission' => 'pages_read_engagement', 'status' => 'granted'],
                ['permission' => 'instagram_basic', 'status' => 'granted'],
            ]],
        ];
        $this->fakeGraph();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function fakeGraph(): void
    {
        Http::fake(function (HttpRequest $r) {
            $url = $r->url();
            $path = parse_url($url, PHP_URL_PATH);
            $g = $this->graph;
            if ($r->method() === 'DELETE') {
                return Http::response(['success' => true]);
            }
            if (str_ends_with($path, '/oauth/access_token')) {
                return $r->method() === 'POST'
                    ? Http::response(['access_token' => 'EAAshort', 'token_type' => 'bearer'])
                    : Http::response(['access_token' => self::USER_TOKEN, 'token_type' => 'bearer', 'expires_in' => 5184000]);
            }
            if (str_ends_with($path, '/debug_token')) {
                return Http::response(['data' => ['user_id' => '9988', 'expires_at' => now()->addDays(60)->timestamp, 'scopes' => $g['scopes'], 'is_valid' => true]]);
            }
            if (str_ends_with($path, '/me/accounts')) {
                return is_int($g['accounts'][0] ?? null) ? Http::response($g['accounts'][1], $g['accounts'][0]) : Http::response($g['accounts']);
            }
            if (str_ends_with($path, '/me/permissions')) {
                return Http::response($g['permissions']);
            }
            if (str_ends_with($path, '/1001')) {
                return Http::response($g['page'][1], $g['page'][0]);
            }
            if (str_ends_with($path, '/1002')) {
                return Http::response($g['page2'][1], $g['page2'][0]);
            }
            if (str_ends_with($path, '/1784001')) {
                return Http::response($g['ig'][1], $g['ig'][0]);
            }

            return Http::response(['error' => ['message' => 'inesperado ' . $url, 'code' => 100]], 400);
        });
    }

    private function url(Company $c, string $suffix): string
    {
        return "/api/v1/companies/{$c->id}{$suffix}";
    }

    private function as(User $u): self
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u, 'sanctum');
    }

    private function impersonationHeaders(User $target): array
    {
        $this->app['auth']->forgetGuards();
        $nt = $target->createToken('impersonation', ['impersonation'], now()->addMinutes(30));
        ImpersonationSession::create(['root_id' => $this->root->id, 'target_user_id' => $target->id, 'company_id' => $target->company_id,
            'token_id' => $nt->accessToken->getKey(), 'ip' => '127.0.0.1', 'user_agent' => 'test', 'started_at' => now()]);

        return ['Authorization' => 'Bearer ' . $nt->plainTextToken];
    }

    /** Pede o URL de autorização como admin e devolve o state (nonce). */
    private function startAuth(): string
    {
        $r = $this->as($this->adminA)->getJson($this->url($this->a, '/integrations/social/auth-url'))->assertOk();
        parse_str((string) parse_url($r->json('data.url'), PHP_URL_QUERY), $q);

        return $q['state'];
    }

    /** Ligação já autorizada e com as contas escolhidas (Instagram e as duas Páginas, principal a 1001). */
    private function connected(): SocialConnection
    {
        $c = SocialConnection::create([
            'company_id' => $this->a->id, 'access_token' => self::USER_TOKEN, 'granted_scopes' => SocialConnection::SCOPES,
            'status' => SocialConnection::STATUS_ACTIVE, 'connected_at' => now(), 'token_expires_at' => now()->addDays(60),
        ]);
        foreach ([['facebook', '1001', '1001', self::PAGE_TOKEN, true], ['facebook', '1002', '1002', self::PAGE_TOKEN_2, false], ['instagram', '1784001', '1001', self::PAGE_TOKEN, true]] as [$platform, $id, $page, $token, $primary]) {
            SocialConnectionAccount::create(['company_id' => $this->a->id, 'social_connection_id' => $c->id, 'platform' => $platform,
                'external_id' => $id, 'page_id' => $page, 'name' => 'Quebom', 'username' => $platform === 'instagram' ? 'quebom' : null,
                'page_access_token' => $token, 'is_primary' => $primary]);
        }

        return $c;
    }

    // ── OAuth ──────────────────────────────────────────────────────────────────

    public function test_auth_url_asks_only_the_three_social_permissions_with_a_single_use_nonce(): void
    {
        $r = $this->as($this->adminA)->getJson($this->url($this->a, '/integrations/social/auth-url'))->assertOk();
        parse_str((string) parse_url($r->json('data.url'), PHP_URL_QUERY), $q);

        $this->assertSame('pages_show_list,pages_read_engagement,instagram_basic', $q['scope']);
        $this->assertStringNotContainsString('ads_read', $r->json('data.url'));
        $this->assertSame('https://dev.exemplo.pt/api/oauth/meta/social/callback', $q['redirect_uri']);
        $this->assertSame('rerequest', $q['auth_type']);
        $this->assertSame(['company_id' => $this->a->id, 'user_id' => $this->adminA->id], Cache::get(SocialConnectionService::stateKey($q['state'])));

        // A ligação dos anúncios continua a pedir só ads_read, no seu callback.
        $ads = $this->as($this->adminA)->getJson($this->url($this->a, '/integrations/meta/oauth-url'))->assertOk();
        parse_str((string) parse_url($ads->json('data.url'), PHP_URL_QUERY), $qa);
        $this->assertSame('ads_read', $qa['scope']);
        $this->assertSame('https://dev.exemplo.pt/api/oauth/meta/callback', $qa['redirect_uri']);
    }

    public function test_callback_stores_encrypted_token_and_waits_for_the_choice(): void
    {
        $state = $this->startAuth();

        $this->get('/api/oauth/meta/social/callback?code=abc&state=' . $state)
            ->assertRedirect('https://dev.exemplo.pt/app/companies/' . $this->a->id . '?social=choose');

        $c = SocialConnection::where('company_id', $this->a->id)->firstOrFail();
        $this->assertSame(SocialConnection::STATUS_PENDING_SELECTION, $c->status);
        $this->assertSame(self::USER_TOKEN, $c->access_token);
        $this->assertSame('9988', $c->meta_user_id);
        $this->assertSame($this->adminA->id, $c->connected_by_user_id);
        $raw = DB::table('social_connections')->where('id', $c->id)->value('access_token');
        $this->assertStringNotContainsString(self::USER_TOKEN, $raw, 'O token tem de ficar cifrado.');

        // Nonce de uso único: o mesmo state já não serve.
        $this->get('/api/oauth/meta/social/callback?code=abc&state=' . $state)
            ->assertRedirect('https://dev.exemplo.pt/app/?social=error&reason=state');
        // Recusa no diálogo da Meta.
        $this->get('/api/oauth/meta/social/callback?error=access_denied&state=' . $this->startAuth())
            ->assertRedirect('https://dev.exemplo.pt/app/companies/' . $this->a->id . '?social=error&reason=denied');
    }

    public function test_callback_without_all_permissions_saves_nothing_and_returns_what_was_granted(): void
    {
        $this->graph['scopes'] = ['pages_show_list', 'pages_read_engagement'];
        $this->graph['permissions']['data'][2]['status'] = 'declined';
        $state = $this->startAuth();

        $this->get('/api/oauth/meta/social/callback?code=abc&state=' . $state)
            ->assertRedirect('https://dev.exemplo.pt/app/companies/' . $this->a->id . '?social=error&reason=scopes');

        $this->assertDatabaseCount('social_connections', 0);
        $deletes = collect(Http::recorded())->filter(fn ($p) => $p[0]->method() === 'DELETE')->map(fn ($p) => parse_url($p[0]->url(), PHP_URL_PATH))->values()->all();
        $this->assertSame(['/v25.0/me/permissions/pages_show_list', '/v25.0/me/permissions/pages_read_engagement'], $deletes);
    }

    public function test_callback_when_meta_did_not_offer_the_permissions_reports_awaiting_approval(): void
    {
        // Sem papel na app e sem aprovação: a Meta não concede nem regista como recusada.
        $this->graph['scopes'] = ['public_profile'];
        $this->graph['permissions'] = ['data' => [['permission' => 'public_profile', 'status' => 'granted']]];

        $this->get('/api/oauth/meta/social/callback?code=abc&state=' . $this->startAuth())
            ->assertRedirect('https://dev.exemplo.pt/app/companies/' . $this->a->id . '?social=error&reason=not_approved');
        $this->assertDatabaseCount('social_connections', 0);
    }

    // ── Escolha das contas ─────────────────────────────────────────────────────

    public function test_admin_chooses_pages_and_instagram_accounts_confirmed_on_meta(): void
    {
        Queue::fake();
        SocialConnection::create(['company_id' => $this->a->id, 'access_token' => self::USER_TOKEN, 'status' => SocialConnection::STATUS_PENDING_SELECTION]);

        $cand = $this->as($this->adminA)->getJson($this->url($this->a, '/integrations/social/candidates'))->assertOk();
        $this->assertSame(['1001', '1002'], array_column($cand->json('data.pages'), 'id'));
        $this->assertSame('quebom', $cand->json('data.pages.0.instagram.username'));
        $this->assertNull($cand->json('data.pages.1.instagram'));
        $this->assertStringNotContainsString(self::PAGE_TOKEN, $cand->getContent(), 'Os tokens das Páginas nunca saem do backend.');

        // Um id que não está na autorização é recusado.
        $this->as($this->adminA)->putJson($this->url($this->a, '/integrations/social/accounts'), ['facebook' => ['1001', '9999'], 'instagram' => []])
            ->assertStatus(422);
        // Sem nenhuma conta.
        $this->as($this->adminA)->putJson($this->url($this->a, '/integrations/social/accounts'), ['facebook' => [], 'instagram' => []])
            ->assertStatus(422);

        $r = $this->as($this->adminA)->putJson($this->url($this->a, '/integrations/social/accounts'), [
            'facebook' => ['1001', '1002'], 'instagram' => ['1784001'], 'primary_facebook' => '1002',
        ])->assertOk();

        $this->assertSame(SocialConnection::STATUS_ACTIVE, $r->json('data.status'));
        $this->assertStringNotContainsString(self::PAGE_TOKEN, $r->getContent());
        $accounts = SocialConnectionAccount::where('company_id', $this->a->id)->get()->keyBy('external_id');
        $this->assertCount(3, $accounts);
        $this->assertTrue($accounts['1002']->is_primary);
        $this->assertFalse($accounts['1001']->is_primary);
        $this->assertTrue($accounts['1784001']->is_primary, 'Por omissão, a primeira escolhida de cada rede é a principal.');
        $this->assertSame('1001', $accounts['1784001']->page_id);
        $this->assertSame(self::PAGE_TOKEN, $accounts['1784001']->page_access_token, 'O Instagram lê-se com o token da Página a que está ligado.');
        $this->assertStringNotContainsString(self::PAGE_TOKEN, (string) DB::table('social_connection_accounts')->where('external_id', '1001')->value('page_access_token'));
        Queue::assertPushed(ReadSocialFollowersJob::class, fn ($j) => $j->companyId === $this->a->id);
    }

    // ── Seguidores automáticos ─────────────────────────────────────────────────

    public function test_daily_job_records_api_reading_that_wins_over_the_manual_one(): void
    {
        $this->connected();
        app(FollowerSnapshotService::class)->recordManual($this->a->id, 'instagram', 4000, $this->adminA);

        (new ReadSocialFollowersJob())->handle(app(SocialConnectionService::class));

        $ig = SocialFollowerSnapshot::where('company_id', $this->a->id)->where('platform', 'instagram')->sole();
        $this->assertSame(4200, $ig->followers_count);
        $this->assertSame(SocialFollowerSnapshot::SOURCE_API, $ig->source);
        $this->assertSame(180, $ig->follows_count);
        $this->assertSame('2026-10-05', $ig->snapshot_date->toDateString());
        // Só a Página principal alimenta o histórico; a outra fica lida na conta.
        $fb = SocialFollowerSnapshot::where('company_id', $this->a->id)->where('platform', 'facebook')->sole();
        $this->assertSame(1250, $fb->followers_count);
        $this->assertSame(310, SocialConnectionAccount::where('external_id', '1002')->value('last_followers_count'));

        $c = SocialConnection::where('company_id', $this->a->id)->first();
        $this->assertSame(SocialConnection::STATUS_ACTIVE, $c->status);
        $this->assertNotNull($c->last_read_at);
        $this->assertNull($c->last_error_kind);

        // Com a leitura automática feita, o registo manual do dia é recusado.
        $this->as($this->adminA)->postJson($this->url($this->a, '/followers'), ['platform' => 'instagram', 'followers_count' => 1])->assertStatus(409);
        // O cartão de seguidores mostra que é automático.
        $f = $this->as($this->userA)->getJson($this->url($this->a, '/followers'))->assertOk();
        $this->assertTrue($f->json('data.automation.instagram.connected'));
        $this->assertSame('@quebom', $f->json('data.automation.instagram.account'));
        $this->assertSame('active', $f->json('data.automation.instagram.connection_status'));
    }

    public function test_failed_reading_records_nothing_and_says_when_it_failed(): void
    {
        $this->connected();
        $this->graph['ig'] = [500, ['error' => ['message' => 'Erro temporário', 'code' => 2]]];

        (new ReadSocialFollowersJob())->handle(app(SocialConnectionService::class));

        $this->assertSame(0, SocialFollowerSnapshot::where('company_id', $this->a->id)->where('platform', 'instagram')->count(), 'Só grava quando lê de facto.');
        $this->assertSame(1, SocialFollowerSnapshot::where('company_id', $this->a->id)->where('platform', 'facebook')->count());
        $c = SocialConnection::where('company_id', $this->a->id)->first();
        $this->assertSame(SocialConnection::STATUS_ACTIVE, $c->status);
        $this->assertSame('failed', $c->last_error_kind);
        $this->assertNotNull($c->last_error_at);

        $f = $this->as($this->userA)->getJson($this->url($this->a, '/followers'))->assertOk();
        $this->assertSame('failed', $f->json('data.automation.instagram.last_error_kind'));
        $this->assertNotNull($f->json('data.automation.instagram.last_error_at'));
        $this->assertNull($f->json('data.automation.facebook.last_error_kind'));
    }

    public function test_expired_token_state(): void
    {
        $this->connected();
        $this->graph['page'] = $this->graph['page2'] = $this->graph['ig'] = [400, ['error' => ['message' => 'Error validating access token: Session has expired', 'type' => 'OAuthException', 'code' => 190, 'error_subcode' => 463]]];

        (new ReadSocialFollowersJob())->handle(app(SocialConnectionService::class));

        $this->assertDatabaseCount('social_follower_snapshots', 0);
        $c = SocialConnection::where('company_id', $this->a->id)->first();
        $this->assertSame(SocialConnection::STATUS_EXPIRED, $c->status);
        $this->assertSame(SocialConnection::STATUS_EXPIRED, $c->last_error_kind);
        // Expirada: o job diário deixa de tentar até voltar a ligar.
        Http::fake();
        (new ReadSocialFollowersJob())->handle(app(SocialConnectionService::class));
        Http::assertNothingSent();
    }

    public function test_refusal_without_approved_access_is_reported_as_awaiting_meta_approval(): void
    {
        $this->connected();
        $refusal = [403, ['error' => ['message' => '(#10) This endpoint requires the pages_read_engagement permission', 'type' => 'OAuthException', 'code' => 10]]];
        $this->graph['page'] = $this->graph['page2'] = $this->graph['ig'] = $refusal;

        (new ReadSocialFollowersJob())->handle(app(SocialConnectionService::class));

        $c = SocialConnection::where('company_id', $this->a->id)->first();
        $this->assertSame(SocialConnection::STATUS_NOT_APPROVED, $c->status, 'As três permissões continuam concedidas: a recusa vem do nível de acesso da app.');
        $this->assertDatabaseCount('social_follower_snapshots', 0);
        $this->assertSame(
            'A leitura automática fica disponível depois da aprovação da Meta. Pode continuar a registar os seguidores manualmente.',
            SocialConnectionService::statusMessage($c->status)
        );
        // Continua a poder registar manualmente.
        $this->as($this->adminA)->postJson($this->url($this->a, '/followers'), ['platform' => 'instagram', 'followers_count' => 4100])->assertOk();

        // O job continua a tentar; quando a Meta aprovar, a leitura volta e o estado também.
        $this->graph['page'] = [200, ['followers_count' => 1250]];
        $this->graph['page2'] = [200, ['followers_count' => 310]];
        $this->graph['ig'] = [200, ['followers_count' => 4200]];
        (new ReadSocialFollowersJob())->handle(app(SocialConnectionService::class));
        $this->assertSame(SocialConnection::STATUS_ACTIVE, $c->fresh()->status);
        $this->assertSame('api', SocialFollowerSnapshot::where('company_id', $this->a->id)->where('platform', 'instagram')->value('source'));
    }

    public function test_permission_removed_on_facebook_asks_to_connect_again(): void
    {
        $this->connected();
        $this->graph['page'] = $this->graph['page2'] = $this->graph['ig'] = [403, ['error' => ['message' => '(#200) Requires instagram_basic permission', 'code' => 200]]];
        $this->graph['permissions']['data'][2]['status'] = 'declined';

        (new ReadSocialFollowersJob())->handle(app(SocialConnectionService::class));

        $this->assertSame(SocialConnection::STATUS_PERMISSION_REMOVED, SocialConnection::where('company_id', $this->a->id)->value('status'));
    }

    public function test_daily_job_is_scheduled_at_dawn_lisbon_time(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'social-followers-daily');
        $this->assertNotNull($event);
        $this->assertSame('30 4 * * *', $event->expression);
        $this->assertSame('Europe/Lisbon', $event->timezone);
    }

    // ── Desligar ───────────────────────────────────────────────────────────────

    public function test_disconnect_removes_only_the_social_permissions_one_by_one_and_leaves_ads_untouched(): void
    {
        $this->connected();
        $ads = CompanyIntegration::create(['company_id' => $this->a->id, 'platform' => 'meta', 'access_token' => 'EAAads-token', 'account_id' => '123', 'status' => 'active']);
        (new ReadSocialFollowersJob())->handle(app(SocialConnectionService::class));

        $r = $this->as($this->adminA)->deleteJson($this->url($this->a, '/integrations/social'))->assertOk();
        $this->assertTrue($r->json('data.permissions_revoked'));
        $this->assertFalse($r->json('data.purged'));

        $deletes = collect(Http::recorded())->filter(fn ($p) => $p[0]->method() === 'DELETE');
        $this->assertSame(
            ['/v25.0/me/permissions/pages_show_list', '/v25.0/me/permissions/pages_read_engagement', '/v25.0/me/permissions/instagram_basic'],
            $deletes->map(fn ($p) => parse_url($p[0]->url(), PHP_URL_PATH))->values()->all(),
            'Nunca DELETE /me/permissions sem nome (retiraria também ads_read).'
        );
        $deletes->each(fn ($p) => $this->assertStringContainsString('access_token=' . self::USER_TOKEN, $p[0]->url()));

        // Anúncios intactos.
        $ads->refresh();
        $this->assertSame('active', $ads->status);
        $this->assertSame('EAAads-token', $ads->access_token);
        // Por omissão, o histórico de seguidores fica; o token e as contas saem.
        $this->assertSame(2, SocialFollowerSnapshot::where('company_id', $this->a->id)->count());
        $c = SocialConnection::where('company_id', $this->a->id)->first();
        $this->assertSame(SocialConnection::STATUS_REVOKED, $c->status);
        $this->assertNull($c->access_token);
        $this->assertSame(0, SocialConnectionAccount::where('company_id', $this->a->id)->count());
        $this->assertFalse($this->as($this->userA)->getJson($this->url($this->a, '/followers'))->json('data.automation.instagram.connected'));
    }

    public function test_disconnect_with_purge_deletes_automatic_history_and_keeps_manual(): void
    {
        $this->connected();
        (new ReadSocialFollowersJob())->handle(app(SocialConnectionService::class));
        SocialFollowerSnapshot::create(['company_id' => $this->a->id, 'platform' => 'instagram', 'snapshot_date' => '2026-09-01', 'followers_count' => 3900, 'source' => 'manual']);
        SocialFollowerSnapshot::create(['company_id' => $this->b->id, 'platform' => 'instagram', 'snapshot_date' => '2026-09-01', 'followers_count' => 50, 'source' => 'api']);

        $this->as($this->adminA)->deleteJson($this->url($this->a, '/integrations/social'), ['purge' => true, 'confirmation' => 'apagar'])->assertStatus(422);
        $r = $this->as($this->adminA)->deleteJson($this->url($this->a, '/integrations/social'), ['purge' => true, 'confirmation' => 'APAGAR'])->assertOk();

        $this->assertSame(2, $r->json('data.deleted_snapshots'));
        $this->assertSame(['manual'], SocialFollowerSnapshot::where('company_id', $this->a->id)->pluck('source')->all());
        $this->assertSame(1, SocialFollowerSnapshot::where('company_id', $this->b->id)->count(), 'Os dados de outra empresa nunca são tocados.');
        $this->assertDatabaseCount('social_connections', 0);
    }

    // ── Segurança: permissões, impersonation, tenancy ──────────────────────────

    public function test_only_the_company_admin_can_connect_choose_or_disconnect(): void
    {
        $this->connected();
        foreach ([$this->userA, $this->root] as $who) {
            $this->as($who)->getJson($this->url($this->a, '/integrations/social/auth-url'))->assertForbidden();
            $this->as($who)->getJson($this->url($this->a, '/integrations/social/candidates'))->assertForbidden();
            $this->as($who)->putJson($this->url($this->a, '/integrations/social/accounts'), ['facebook' => ['1001'], 'instagram' => []])->assertForbidden();
            $this->as($who)->deleteJson($this->url($this->a, '/integrations/social'))->assertForbidden();
        }
        // Ver o estado: qualquer utilizador da empresa, sem poder gerir.
        $s = $this->as($this->userA)->getJson($this->url($this->a, '/integrations/social'))->assertOk();
        $this->assertFalse($s->json('data.can_manage'));
        $this->assertStringNotContainsString(self::USER_TOKEN, $s->getContent());
        $this->assertStringNotContainsString(self::PAGE_TOKEN, $s->getContent());
        $this->assertTrue($this->as($this->adminA)->getJson($this->url($this->a, '/integrations/social'))->json('data.can_manage'));
        $this->assertSame(SocialConnection::STATUS_ACTIVE, SocialConnection::where('company_id', $this->a->id)->value('status'));
    }

    public function test_impersonation_cannot_connect_or_disconnect(): void
    {
        $this->connected();
        $h = $this->impersonationHeaders($this->adminA);

        $this->getJson($this->url($this->a, '/integrations/social/auth-url'), $h)->assertForbidden();
        $this->getJson($this->url($this->a, '/integrations/social/candidates'), $h)->assertForbidden();
        $this->putJson($this->url($this->a, '/integrations/social/accounts'), ['facebook' => ['1001'], 'instagram' => []], $h)->assertForbidden();
        $this->deleteJson($this->url($this->a, '/integrations/social'), [], $h)->assertForbidden();
        $this->assertFalse($this->getJson($this->url($this->a, '/integrations/social'), $h)->assertOk()->json('data.can_manage'));
        $this->assertSame(SocialConnection::STATUS_ACTIVE, SocialConnection::where('company_id', $this->a->id)->value('status'));
        $this->assertSame(0, collect(Http::recorded())->filter(fn ($p) => $p[0]->method() === 'DELETE')->count());
    }

    public function test_tenancy_another_company_admin_sees_and_changes_nothing(): void
    {
        $this->connected();

        $this->as($this->adminB)->getJson($this->url($this->a, '/integrations/social'))->assertForbidden();
        $this->as($this->adminB)->getJson($this->url($this->a, '/integrations/social/auth-url'))->assertForbidden();
        $this->as($this->adminB)->deleteJson($this->url($this->a, '/integrations/social'))->assertForbidden();
        $this->as($this->adminB)->putJson($this->url($this->a, '/integrations/social/accounts'), ['facebook' => ['1001'], 'instagram' => []])->assertForbidden();

        // O estado da empresa B não mostra nada da A.
        $s = $this->as($this->adminB)->getJson($this->url($this->b, '/integrations/social'))->assertOk();
        $this->assertNull($s->json('data.status'));
        $this->assertSame([], $s->json('data.accounts'));
        // E o job de uma empresa só lê as contas dela.
        app(SocialConnectionService::class)->readCompany($this->b->id);
        $this->assertDatabaseCount('social_follower_snapshots', 0);
    }
}
