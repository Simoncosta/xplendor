<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Company;
use App\Models\CompanyConnectionEvent;
use App\Models\CompanyIntegration;
use App\Models\CompanyManagement;
use App\Models\CompanySetupLink;
use App\Models\SocialConnection;
use App\Models\SupportTicket;
use App\Models\SupportTicketTask;
use App\Models\User;
use App\Services\Agency\AgencyConnectionsService;
use App\Services\Ga4\Ga4ClientInterface;
use App\Services\Setup\SetupPublicService;
use App\Services\Social\SocialConnectionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * F1c-1: link de configuração do cliente (sem conta). A Meta e o GA4 são sempre simulados.
 * Geração pelos três papéis, validade, renovação, revogação, um só link ativo, segurança do
 * link, OAuth ligado ao link, passos refeitos, conclusão com aviso e tarefas marcadas pela
 * chave, app da Meta não aprovada, passo parado (30 minutos), histórico e tenancy.
 */
class CompanySetupLinkTest extends TestCase
{
    use RefreshDatabase;

    private Company $xplendor;
    private Company $agency;
    private Company $client;
    private Company $other;
    private User $root;
    private User $agencyAdmin;
    private User $member;
    private User $clientAdmin;
    private User $clientUser;
    private User $otherAdmin;

    /** Respostas simuladas da Meta. */
    private array $graph = [];

    private object $ga4;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00', 'UTC'));
        config([
            'services.meta.app_id' => '111', 'services.meta.app_secret' => 'segredo',
            'services.meta.redirect_uri' => 'https://dev.exemplo.pt/api/oauth/meta/callback', 'services.meta.social_redirect_uri' => null,
            'services.ga4.sa_email' => 'leitura@xplendor-ga4.iam.gserviceaccount.com',
            'app.frontend_url' => 'https://app.exemplo.pt/app',
        ]);
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $mk = fn (string $name, string $nipc) => Company::create(['nipc' => $nipc, 'fiscal_name' => $name, 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->xplendor = $mk('XPLENDOR', '500090001');
        $this->agency = $mk('Agência Norte', '500090002');
        $this->agency->forceFill(['agency_enabled_at' => now(), 'agency_notification_email' => 'avisos@norte.pt'])->save();
        $this->client = $mk('Domiway Lda', '500090003');
        $this->other = $mk('Outra Lda', '500090004');
        $this->root = User::factory()->create(['company_id' => $this->xplendor->id, 'role' => 'root']);
        $this->agencyAdmin = User::factory()->create(['company_id' => $this->agency->id, 'role' => 'admin', 'email' => 'ana@norte.pt']);
        $this->member = User::factory()->create(['company_id' => $this->agency->id, 'role' => 'user']);
        $this->clientAdmin = User::factory()->create(['company_id' => $this->client->id, 'role' => 'admin', 'email' => 'joana@domiway.pt']);
        $this->clientUser = User::factory()->create(['company_id' => $this->client->id, 'role' => 'user']);
        $this->otherAdmin = User::factory()->create(['company_id' => $this->other->id, 'role' => 'admin']);
        CompanyManagement::create(['agency_company_id' => $this->agency->id, 'managed_company_id' => $this->client->id, 'origin' => 'platform',
            'status' => 'active', 'active_key' => $this->client->id, 'team_scope' => 'all', 'requested_at' => now()->subMonth(), 'responded_at' => now()->subMonth()]);

        $this->graph = [
            'scopes' => ['pages_show_list', 'pages_read_engagement', 'instagram_basic', 'ads_read', 'public_profile'],
            'permissions' => ['data' => []],
            'adaccounts' => ['data' => [
                ['id' => 'act_5551', 'account_id' => '5551', 'name' => 'Domiway Anúncios', 'account_status' => 1, 'currency' => 'EUR', 'business' => ['name' => 'Domiway']],
                ['id' => 'act_5552', 'account_id' => '5552', 'name' => 'Conta antiga', 'account_status' => 2, 'currency' => 'EUR'],
            ]],
        ];
        Http::fake(function (HttpRequest $r) {
            $path = (string) parse_url($r->url(), PHP_URL_PATH);
            if ($r->method() === 'DELETE') {
                return Http::response(['success' => true]);
            }
            if (str_ends_with($path, '/oauth/access_token')) {
                return $r->method() === 'POST' ? Http::response(['access_token' => 'EAAshort']) : Http::response(['access_token' => 'EAAlong', 'expires_in' => 5184000]);
            }
            if (str_ends_with($path, '/debug_token')) {
                return Http::response(['data' => ['user_id' => '9988', 'expires_at' => now()->addDays(60)->timestamp, 'scopes' => $this->graph['scopes'], 'is_valid' => true]]);
            }
            if (str_ends_with($path, '/me/accounts')) {
                return Http::response(['data' => [
                    ['id' => '1001', 'name' => 'Domiway', 'access_token' => 'EAApage', 'instagram_business_account' => ['id' => '1784001', 'username' => 'domiway', 'name' => 'Domiway']],
                ]]);
            }
            if (str_ends_with($path, '/me/adaccounts')) {
                return Http::response($this->graph['adaccounts']);
            }
            if (str_ends_with($path, '/me/permissions')) {
                return Http::response($this->graph['permissions']);
            }

            return Http::response(['followers_count' => 100, 'id' => '1'], 200);
        });

        $this->ga4 = new class implements Ga4ClientInterface {
            public ?string $fail = null;

            public function runReport(int $propertyId, array $spec): array
            {
                if ($this->fail) {
                    throw new \RuntimeException($this->fail);
                }

                return ['rows' => [['metrics' => [42]]]];
            }
        };
        $this->app->instance(Ga4ClientInterface::class, $this->ga4);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Auxiliares ───────────────────────────────────────────────────────────

    private function as(User $u): self
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->actingAs($u, 'sanctum');
    }

    private function url(Company $c, string $suffix): string
    {
        return "/api/v1/companies/{$c->id}{$suffix}";
    }

    /** Gera um link e devolve o token (tirado do fragmento do endereço). */
    private function generate(array $steps = ['social', 'meta_ads', 'ga4'], ?User $by = null, ?Company $company = null): string
    {
        $res = $this->as($by ?? $this->agencyAdmin)->postJson($this->url($company ?? $this->client, '/setup-links'), ['steps' => $steps])->assertCreated();

        return explode('#', (string) $res->json('data.link.url'))[1];
    }

    private function pub(string $token): self
    {
        $this->app['auth']->forgetGuards();

        return $this->flushHeaders()->withHeaders(['X-Setup-Token' => $token]);
    }

    /** Simula o regresso do Facebook ao callback de sempre; devolve o destino do redirecionamento. */
    private function metaCallback(string $authUrl, string $callbackPath, array $extra = ['code' => 'ok']): string
    {
        parse_str((string) parse_url($authUrl, PHP_URL_QUERY), $q);
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return (string) $this->get($callbackPath . '?' . http_build_query(['state' => $q['state']] + $extra))->assertRedirect()->headers->get('Location');
    }

    private function link(): CompanySetupLink
    {
        return CompanySetupLink::where('company_id', $this->client->id)->orderByDesc('id')->firstOrFail();
    }

    private function onboardingTask(Company $company, ?string $key, string $title = 'Tarefa'): SupportTicketTask
    {
        $ticket = SupportTicket::create(['company_id' => $company->id, 'user_id' => $this->root->id, 'type' => SupportTicket::TYPE_ONBOARDING, 'title' => 'Arranque',
            'description' => 'x', 'status' => 'open', 'priority' => 'normal']);

        return SupportTicketTask::create(['support_ticket_id' => $ticket->id, 'position' => 0, 'group_label' => 'Social Media', 'title' => $title, 'task_key' => $key]);
    }

    // ── Gerar: três papéis e 403 para os outros ──────────────────────────────

    public function test_admin_of_the_company_root_and_managing_agency_admin_generate_and_others_get_403(): void
    {
        foreach ([$this->clientAdmin, $this->root, $this->agencyAdmin] as $who) {
            $this->as($who)->postJson($this->url($this->client, '/setup-links'), ['steps' => ['social']])->assertCreated()
                ->assertJsonPath('data.link.state', 'open')->assertJsonPath('data.meta_app_review_pending', true);
        }
        foreach ([$this->clientUser, $this->member, $this->otherAdmin] as $who) {
            $this->as($who)->postJson($this->url($this->client, '/setup-links'), ['steps' => ['social']])->assertForbidden();
            $this->as($who)->getJson($this->url($this->client, '/setup-link'))->assertForbidden();
        }
        $this->as($this->agencyAdmin)->postJson($this->url($this->client, '/setup-links'), ['steps' => []])->assertStatus(422);
        $this->as($this->agencyAdmin)->postJson($this->url($this->client, '/setup-links'), ['steps' => ['tiktok']])->assertStatus(422);
        // Com a app aprovada, o aviso preventivo desaparece.
        config(['services.meta.app_approved' => true]);
        $this->as($this->agencyAdmin)->getJson($this->url($this->client, '/setup-link'))->assertJsonPath('data.meta_app_review_pending', false);
    }

    // ── Validade, renovação, revogação, um só ativo ──────────────────────────

    public function test_validity_renewal_revocation_and_a_single_active_link(): void
    {
        $first = $this->generate(['social']);
        $link = $this->link();
        $this->assertSame('2026-10-28', $link->expires_at->toDateString());

        // Um link novo revoga o anterior (substituído).
        $second = $this->generate(['ga4']);
        $this->assertSame('replaced', CompanySetupLink::where('token_hash', CompanySetupLink::hashToken($first))->value('revoked_reason'));
        $this->assertSame(1, CompanySetupLink::where('company_id', $this->client->id)->whereNull('revoked_at')->count());
        $this->pub($first)->getJson('/api/public/setup')->assertOk()->assertJsonPath('data.state', 'revoked')
            ->assertJsonPath('data.message', 'Este link de configuração já não está ativo. Peça um link novo à sua agência.')
            ->assertJsonMissingPath('data.steps');
        $this->pub($first)->getJson('/api/public/setup/social/auth-url')->assertStatus(409);

        // Expira aos 14 dias: erro claro e nenhuma ação.
        Carbon::setTestNow(now()->addDays(15));
        $this->pub($second)->getJson('/api/public/setup')->assertJsonPath('data.state', 'expired')
            ->assertJsonPath('data.message', 'Este link de configuração expirou. Peça um link novo à sua agência.');
        $this->pub($second)->postJson('/api/public/setup/ga4/verify', ['property_id' => '398765432'])->assertStatus(409);

        // Renovar prolonga o MESMO link (o mesmo endereço) por mais 14 dias.
        $id = $this->link()->id;
        $res = $this->as($this->clientAdmin)->postJson($this->url($this->client, "/setup-links/{$id}/extend"))->assertOk();
        $this->assertStringEndsWith('#' . $second, $res->json('data.link.url'));
        $this->assertSame(now()->addDays(14)->toDateString(), $this->link()->expires_at->toDateString());
        $this->pub($second)->getJson('/api/public/setup')->assertJsonPath('data.state', 'open');

        // Revogar: deixa de funcionar e já não se renova.
        $this->as($this->root)->postJson($this->url($this->client, "/setup-links/{$id}/revoke"))->assertOk()->assertJsonPath('data.link.state', 'revoked');
        $this->pub($second)->getJson('/api/public/setup')->assertJsonPath('data.state', 'revoked');
        $this->as($this->clientAdmin)->postJson($this->url($this->client, "/setup-links/{$id}/extend"))->assertStatus(409);
    }

    // ── Segurança do link ────────────────────────────────────────────────────

    public function test_token_only_in_the_fragment_and_header_with_noindex_and_hashed_at_rest(): void
    {
        $token = $this->generate(['social', 'ga4']);
        $link = $this->link();
        $this->assertSame(CompanySetupLink::hashToken($token), $link->token_hash);
        $this->assertNotSame($token, $link->token_encrypted);
        $this->assertSame('https://app.exemplo.pt/app/configurar#' . $token, $link->url());
        $this->assertArrayNotHasKey('token_hash', $link->toArray());

        $res = $this->pub($token)->getJson('/api/public/setup')->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertJsonPath('data.company.name', 'Domiway Lda')->assertJsonPath('data.agency.name', 'Agência Norte')
            ->assertJsonPath('data.ga4.sa_email', 'leitura@xplendor-ga4.iam.gserviceaccount.com')
            ->assertJsonPath('data.privacy_url', 'https://xplendor.tech/politica-de-privacidade/');
        $this->assertSame(['social', 'ga4'], array_column($res->json('data.steps'), 'key'));
        $this->assertStringNotContainsString($token, $res->getContent());

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->getJson('/api/public/setup')->assertNotFound();
        $this->getJson('/api/public/setup?token=' . $token)->assertNotFound();
        $this->getJson('/api/public/setup/' . $token)->assertNotFound();
        $this->pub('curto')->getJson('/api/public/setup')->assertNotFound();
        $this->pub(str_repeat('a', 64))->getJson('/api/public/setup')->assertNotFound();
        $this->assertCount(0, Alert::all());

        // Aberturas reais contadas (sem IP); a equipa da agência não conta.
        $this->pub($token)->postJson('/api/public/setup/open', ['visitor_id' => 'visitante-cliente-123456'])->assertOk();
        $this->assertSame(1, $this->link()->open_count);
    }

    // ── Facebook e Instagram pelo link ───────────────────────────────────────

    public function test_social_oauth_is_bound_to_the_link_and_the_step_can_be_redone(): void
    {
        $token = $this->generate(['social']);
        $link = $this->link();
        $this->pub($token)->getJson('/api/public/setup/social/candidates')->assertStatus(409); // ainda sem autorização

        $authUrl = $this->pub($token)->getJson('/api/public/setup/social/auth-url')->assertOk()->json('data.url');
        parse_str((string) parse_url($authUrl, PHP_URL_QUERY), $q);
        // O nonce leva o LINK, não um utilizador.
        $this->assertSame(['company_id' => $this->client->id, 'user_id' => null, 'setup_link_id' => $link->id], Cache::get(SocialConnectionService::stateKey($q['state'])));
        $this->assertNotNull($this->link()->step('social')['started_at']);

        $location = $this->metaCallback($authUrl, '/api/oauth/meta/social/callback');
        $this->assertSame('https://dev.exemplo.pt/app/configurar?passo=social&resultado=escolher#' . $token, $location);
        $conn = SocialConnection::where('company_id', $this->client->id)->firstOrFail();
        $this->assertSame([null, $link->id], [$conn->connected_by_user_id, $conn->setup_link_id]);

        $this->pub($token)->getJson('/api/public/setup/social/candidates')->assertOk()->assertJsonPath('data.pages.0.name', 'Domiway');
        $this->pub($token)->putJson('/api/public/setup/social/accounts', ['facebook' => ['1001'], 'instagram' => ['1784001']])->assertOk()
            ->assertJsonPath('data.step.status', 'done')->assertJsonPath('data.step.detail.instagram.0', '@domiway')->assertJsonPath('data.completed', true);

        // Refazer enquanto o link é válido: só o Instagram.
        $this->metaCallback($this->pub($token)->getJson('/api/public/setup/social/auth-url')->json('data.url'), '/api/oauth/meta/social/callback');
        $this->pub($token)->putJson('/api/public/setup/social/accounts', ['facebook' => [], 'instagram' => ['1784001']])->assertOk()->assertJsonPath('data.step.status', 'done');
        $events = CompanyConnectionEvent::where('company_id', $this->client->id)->where('kind', 'social')->get();
        $this->assertCount(2, $events);
        $this->assertSame([$link->id, null], [$events->last()->setup_link_id, $events->last()->user_id]);

        // A ligação do cliente não conta como da agência (fica no fim da relação), mas a agência pode desligá-la.
        $this->assertSame([], app(AgencyConnectionsService::class)->agencyMade($this->client->id, $this->agency->id));
        $this->as($this->agencyAdmin)->deleteJson($this->url($this->client, '/integrations/social'))->assertOk();
        $this->assertSame(['connected', 'connected', 'disconnected'], CompanyConnectionEvent::where('kind', 'social')->orderBy('id')->pluck('action')->all());
        $this->assertSame($this->agencyAdmin->id, CompanyConnectionEvent::where('action', 'disconnected')->value('user_id'));
        $history = $this->as($this->clientUser)->getJson($this->url($this->client, '/integrations/history'))->assertOk()->json('data.events');
        $this->assertSame(['user', 'setup_link', 'setup_link'], array_column($history, 'origin'));
    }

    // ── Anúncios da Meta pelo link: escolher numa lista ──────────────────────

    public function test_ads_oauth_bound_to_the_link_and_the_account_is_chosen_from_the_list(): void
    {
        $token = $this->generate(['meta_ads']);
        $link = $this->link();
        $authUrl = $this->pub($token)->getJson('/api/public/setup/ads/auth-url')->assertOk()->json('data.url');
        $this->assertStringContainsString('scope=ads_read', $authUrl);

        $location = $this->metaCallback($authUrl, '/api/oauth/meta/callback');
        $this->assertSame('https://dev.exemplo.pt/app/configurar?passo=meta_ads&resultado=escolher#' . $token, $location);
        $integration = CompanyIntegration::where('company_id', $this->client->id)->where('platform', 'meta')->firstOrFail();
        $this->assertSame([null, $link->id, 'active'], [$integration->connected_by_user_id, $integration->setup_link_id, $integration->status]);

        $accounts = $this->pub($token)->getJson('/api/public/setup/ads/accounts')->assertOk()->json('data.accounts');
        $this->assertSame([['5551', 'Domiway Anúncios', true], ['5552', 'Conta antiga', false]], array_map(fn ($a) => [$a['id'], $a['name'], $a['active']], $accounts));
        $this->pub($token)->putJson('/api/public/setup/ads/account', ['account_id' => '9999'])->assertStatus(422); // fora da lista
        $this->pub($token)->putJson('/api/public/setup/ads/account', ['account_id' => 'act_5551'])->assertOk()
            ->assertJsonPath('data.step.status', 'done')->assertJsonPath('data.step.detail.account_name', 'Domiway Anúncios');
        $this->assertSame('5551', $integration->fresh()->account_id);
        $this->assertSame(['connected', 'account_changed'], CompanyConnectionEvent::where('kind', 'meta_ads')->orderBy('id')->pluck('action')->all());

        // A equipa também lista as contas (para deixar de escrever o ID).
        $this->as($this->agencyAdmin)->getJson($this->url($this->client, '/integrations/meta/ad-accounts'))->assertOk()->assertJsonPath('data.selected', '5551');
        $this->as($this->member)->getJson($this->url($this->client, '/integrations/meta/ad-accounts'))->assertForbidden();
    }

    // ── GA4: verificar acesso ────────────────────────────────────────────────

    public function test_ga4_verify_tests_the_access_and_only_then_saves(): void
    {
        $token = $this->generate(['ga4']);
        $this->pub($token)->postJson('/api/public/setup/ga4/verify', ['property_id' => 'G-ABC123'])->assertStatus(422);

        $this->ga4->fail = 'PERMISSION_DENIED: User does not have sufficient permissions for this property.';
        $msg = $this->pub($token)->postJson('/api/public/setup/ga4/verify', ['property_id' => '398765432'])->assertStatus(422)->json('message');
        $this->assertStringContainsString('ainda não tem acesso a esta propriedade', $msg);
        $this->assertNull(CompanyIntegration::where('company_id', $this->client->id)->where('platform', 'google')->first());
        $this->assertSame('error', $this->link()->step('ga4')['status']);

        $this->ga4->fail = 'NOT_FOUND: Property not found';
        $this->assertStringContainsString('Não foi encontrada nenhuma propriedade', $this->pub($token)->postJson('/api/public/setup/ga4/verify', ['property_id' => '398765432'])->json('message'));

        $this->ga4->fail = null;
        $this->pub($token)->postJson('/api/public/setup/ga4/verify', ['property_id' => '398765432'])->assertOk()->assertJsonPath('data.step.status', 'done');
        $ga = CompanyIntegration::where('company_id', $this->client->id)->where('platform', 'google')->firstOrFail();
        $this->assertSame(['398765432', null, $this->link()->id], [$ga->property_id, $ga->connected_by_user_id, $ga->setup_link_id]);

        // Uma nova tentativa falhada não desfaz o passo feito (a ligação anterior continua).
        $this->ga4->fail = 'PERMISSION_DENIED';
        $this->pub($token)->postJson('/api/public/setup/ga4/verify', ['property_id' => '111111111'])->assertStatus(422);
        $this->assertSame('done', $this->link()->step('ga4')['status']);
        $this->assertSame('398765432', $ga->fresh()->property_id);
    }

    // ── Conclusão: aviso e tarefas marcadas pela chave ───────────────────────

    public function test_completion_notifies_the_agency_once_and_marks_the_onboarding_tasks_by_key(): void
    {
        $social = $this->onboardingTask($this->client, 'social_access', 'Pedir o acesso às redes sociais');
        $ads = $this->onboardingTask($this->client, 'meta_ads_access', 'Pedir o acesso à conta de anúncios');
        $ga = $this->onboardingTask($this->client, 'ga4_access', 'Texto qualquer, mudado pela equipa');
        $sameTextNoKey = $this->onboardingTask($this->client, null, 'Pedir o acesso às redes sociais');
        $otherCompany = $this->onboardingTask($this->other, 'social_access');

        $token = $this->generate(['social', 'ga4']);
        $this->metaCallback($this->pub($token)->getJson('/api/public/setup/social/auth-url')->json('data.url'), '/api/oauth/meta/social/callback');
        $this->pub($token)->putJson('/api/public/setup/social/accounts', ['facebook' => ['1001'], 'instagram' => []])->assertOk()->assertJsonPath('data.completed', false);
        $this->assertNotNull($social->fresh()->done_at); // marcada logo que o passo fica feito
        $this->assertNull($this->link()->completed_at);
        $this->assertSame(0, Alert::where('title', 'like', 'Configuração concluída%')->count());

        $this->pub($token)->postJson('/api/public/setup/ga4/verify', ['property_id' => '398765432'])->assertOk()->assertJsonPath('data.completed', true);
        $this->assertNotNull($this->link()->completed_at);
        $this->assertNotNull($ga->fresh()->done_at);
        $this->assertNull($ads->fresh()->done_at, 'Passo não escolhido: tarefa por marcar.');
        $this->assertNull($sameTextNoKey->fresh()->done_at, 'Nunca pelo texto.');
        $this->assertNull($otherCompany->fresh()->done_at);

        $alert = Alert::where('company_id', $this->agency->id)->where('title', 'Configuração concluída: Domiway Lda')->sole();
        $this->assertSame('/companies/' . $this->client->id . '?tab=integrations', $alert->detail_path);
        Mail::assertQueued(\App\Mail\AgencyNoticeMail::class, fn ($m) => $m->hasTo('ana@norte.pt') && $m->hasTo('avisos@norte.pt'));

        // Refazer um passo depois de concluído não volta a avisar.
        $this->pub($token)->postJson('/api/public/setup/ga4/verify', ['property_id' => '398765432'])->assertOk();
        $this->assertSame(1, Alert::where('title', 'Configuração concluída: Domiway Lda')->count());
    }

    public function test_without_agency_the_company_admins_are_notified(): void
    {
        $token = $this->generate(['ga4'], $this->otherAdmin, $this->other);
        $this->pub($token)->postJson('/api/public/setup/ga4/verify', ['property_id' => '398765432'])->assertOk();
        $this->assertSame(1, Alert::where('company_id', $this->other->id)->where('title', 'Configuração concluída: Outra Lda')->count());
        $this->assertSame(0, Alert::where('company_id', $this->agency->id)->count());
    }

    // ── Meta sem App Review ──────────────────────────────────────────────────

    public function test_meta_app_not_approved_shows_an_honest_message_and_warns_the_agency_once(): void
    {
        $this->graph['scopes'] = ['public_profile']; // a Meta não ofereceu as permissões (não foram recusadas)
        $token = $this->generate(['social', 'meta_ads']);

        foreach (['social' => '/api/oauth/meta/social/callback', 'meta_ads' => '/api/oauth/meta/callback'] as $step => $callback) {
            $path = $step === 'social' ? 'social' : 'ads';
            $location = $this->metaCallback($this->pub($token)->getJson("/api/public/setup/{$path}/auth-url")->json('data.url'), $callback);
            $this->assertSame("https://dev.exemplo.pt/app/configurar?passo={$step}&resultado=indisponivel#{$token}", $location);
        }
        $steps = collect($this->pub($token)->getJson('/api/public/setup')->json('data.steps'))->keyBy('key');
        $this->assertSame('not_approved', $steps['social']['status']);
        $this->assertSame('A ligação ao Facebook ainda não está disponível para a sua conta. A sua agência foi avisada.', $steps['meta_ads']['error']);
        $this->assertNull(SocialConnection::where('company_id', $this->client->id)->first(), 'Nada é guardado.');
        $this->assertSame(2, Alert::where('company_id', $this->agency->id)->where('title', 'like', 'A ligação ao Facebook ainda não está disponível%')->count());

        // Um só aviso por passo e por link.
        $this->metaCallback($this->pub($token)->getJson('/api/public/setup/social/auth-url')->json('data.url'), '/api/oauth/meta/social/callback');
        $this->assertSame(2, Alert::where('company_id', $this->agency->id)->where('title', 'like', 'A ligação ao Facebook ainda não está disponível%')->count());

        // Recusa da própria pessoa: mensagem diferente, sem aviso de app.
        $this->graph['permissions'] = ['data' => [['permission' => 'ads_read', 'status' => 'declined']]];
        $location = $this->metaCallback($this->pub($token)->getJson('/api/public/setup/ads/auth-url')->json('data.url'), '/api/oauth/meta/callback');
        $this->assertStringContainsString('resultado=cancelado', $location);
    }

    // ── Passo iniciado e não concluído ───────────────────────────────────────

    public function test_a_meta_step_started_without_return_warns_the_agency_once_after_30_minutes(): void
    {
        $token = $this->generate(['social', 'ga4']);
        $this->pub($token)->getJson('/api/public/setup/social/auth-url')->assertOk();
        $this->assertSame(0, app(SetupPublicService::class)->notifyStalled());

        Carbon::setTestNow(now()->addMinutes(31));
        $this->assertSame(1, app(SetupPublicService::class)->notifyStalled());
        $this->assertSame(0, app(SetupPublicService::class)->notifyStalled());
        $this->assertSame(1, Alert::where('company_id', $this->agency->id)->where('title', 'O cliente não concluiu a ligação ao Facebook: Domiway Lda')->count());

        // Mesmo voltando a iniciar, não há segundo aviso para o mesmo passo e link.
        $this->pub($token)->getJson('/api/public/setup/social/auth-url')->assertOk();
        Carbon::setTestNow(now()->addMinutes(31));
        $this->assertSame(0, app(SetupPublicService::class)->notifyStalled());
    }

    // ── Tenancy ──────────────────────────────────────────────────────────────

    public function test_tenancy_links_of_one_company_cannot_be_managed_from_another(): void
    {
        $this->generate(['social']);
        $id = $this->link()->id;
        $this->as($this->otherAdmin)->postJson($this->url($this->other, "/setup-links/{$id}/revoke"))->assertNotFound();
        $this->as($this->otherAdmin)->postJson($this->url($this->client, "/setup-links/{$id}/revoke"))->assertForbidden();
        $this->as($this->otherAdmin)->getJson($this->url($this->client, '/integrations/history'))->assertForbidden();
        $this->assertNull($this->link()->revoked_at);

        // Um passo que não faz parte do link não se faz.
        $token = $this->generate(['social']);
        $this->pub($token)->postJson('/api/public/setup/ga4/verify', ['property_id' => '398765432'])->assertNotFound();
        $this->pub($token)->getJson('/api/public/setup/ads/auth-url')->assertNotFound();
    }
}
