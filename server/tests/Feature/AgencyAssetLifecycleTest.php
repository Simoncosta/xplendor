<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\PropagateAgencyMetaTokenJob;
use App\Jobs\ReadSocialFollowersJob;
use App\Models\Alert;
use App\Models\AgencyAssetLink;
use App\Models\AgencyMetaConnection;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\CompanyManagement;
use App\Models\SocialConnection;
use App\Models\SocialConnectionAccount;
use App\Models\User;
use App\Services\Agency\ManagementEndEffects;
use App\Services\Agency\Meta\AgencyAssetLifecycle;
use App\Services\Agency\Meta\AgencyAssetLinkService;
use App\Services\Agency\Meta\FakeMetaBusinessClient;
use App\Services\Agency\Meta\MetaBusinessClient;
use App\Services\Agency\Meta\MetaBusinessClientInterface;
use App\Services\Social\FakeMetaSocialClient;
use App\Services\Social\MetaSocialClientInterface;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Parceiro 5: o ciclo de vida dos ativos da agência. A renovação do token da agência chega às ligações dos
 * clientes (o token de cada Página e as cópias nas contas de anúncios); o que falha passa a "sem acesso",
 * com o motivo; um ativo que sai dos portfólios também; o fim da relação desliga só na XPLENDOR (nada se
 * revoga na Meta). Meta sempre simulada.
 */
class AgencyAssetLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private FakeMetaBusinessClient $meta;
    private FakeMetaSocialClient $social;
    private Company $agency;
    private Company $client;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Bus::fake([ReadSocialFollowersJob::class, \App\Jobs\SyncMetaAccountInsightsJob::class, \App\Jobs\SyncMetaAdInsightsJob::class]);
        $this->meta = new FakeMetaBusinessClient();
        $this->app->instance(MetaBusinessClientInterface::class, $this->meta);
        $this->social = new FakeMetaSocialClient();
        $this->app->instance(MetaSocialClientInterface::class, $this->social);
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 9, 'created_at' => now(), 'updated_at' => now()]);
        $make = fn (string $nipc, string $name) => Company::create(['nipc' => $nipc, 'fiscal_name' => $name, 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->agency = $make('500400201', 'Agência Norte Lda');
        $this->agency->forceFill(['agency_enabled_at' => now()])->save();
        $this->client = $make('500400203', 'Restaurante Cliente Lda');
        CompanyManagement::create(['agency_company_id' => $this->agency->id, 'managed_company_id' => $this->client->id, 'origin' => 'platform',
            'status' => 'active', 'active_key' => $this->client->id, 'team_scope' => 'all', 'requested_at' => now(), 'responded_at' => now()]);
        $this->admin = User::factory()->create(['company_id' => $this->agency->id, 'role' => 'admin']);
        AgencyMetaConnection::create(['agency_company_id' => $this->agency->id, 'meta_user_id' => 'U9', 'access_token' => 'token-agencia',
            'granted_scopes' => AgencyMetaConnection::SCOPES, 'status' => AgencyMetaConnection::STATUS_ACTIVE, 'token_expires_at' => now()->addDays(50)]);
        $this->meta->businesses = [['id' => 'B2', 'name' => 'Restaurante Cliente', 'verification_status' => 'not_verified']];
        $this->meta->assets = ['B2' => [
            'pages' => [['id' => '2002', 'name' => 'Restaurante', 'relation' => 'client', 'instagram' => ['id' => '17842', 'username' => 'restaurante']]],
            'ad_accounts' => [['account_id' => '555', 'name' => 'Anúncios do Restaurante', 'relation' => 'client', 'currency' => 'EUR', 'account_status' => 1]],
            'instagram' => [['id' => '17842', 'username' => 'restaurante', 'relation' => 'client']],
        ]];
        $this->meta->pageTokens = ['2002' => 'token-pagina-2002'];

        $links = app(AgencyAssetLinkService::class);
        foreach ([['page', '2002'], ['instagram', '17842'], ['ad_account', '555']] as [$type, $id]) {
            $links->link($this->agency->id, $this->client->id, $type, $id, $this->admin);
        }
    }

    private function renewAgencyToken(string $token = 'token-novo'): void
    {
        AgencyMetaConnection::where('agency_company_id', $this->agency->id)->first()
            ->forceFill(['access_token' => $token, 'token_expires_at' => now()->addDays(60)])->save();
    }

    private function statuses(): array
    {
        return AgencyAssetLink::orderBy('asset_type')->get()->mapWithKeys(fn ($l) => [$l->asset_type => [$l->status, $l->status_reason]])->all();
    }

    public function test_the_renewed_agency_token_reaches_the_client_connections(): void
    {
        $this->renewAgencyToken();
        $this->meta->pageTokens = ['2002' => 'token-pagina-nova'];
        $this->meta->calls = [];

        $this->assertSame(['linked' => 3, 'no_access' => 0], app(AgencyAssetLifecycle::class)->propagateToken($this->agency->id));
        // Uma só leitura por Página (a Página e o seu Instagram partilham o token), com o token novo.
        $this->assertSame([['pageToken', ['token-novo', '2002']]], $this->meta->calls);
        $connection = SocialConnection::where('company_id', $this->client->id)->first();
        $this->assertSame('token-novo', $connection->access_token);
        $this->assertSame(['token-pagina-nova', 'token-pagina-nova'], SocialConnectionAccount::where('social_connection_id', $connection->id)->orderBy('platform')->pluck('page_access_token')->all());
        $ads = CompanyIntegration::where('company_id', $this->client->id)->where('platform', 'meta')->first();
        $this->assertSame(['token-novo', 'agencia', 'active'], [(string) $ads->access_token, $ads->origin, $ads->status]);
        $this->assertNotNull(AgencyAssetLink::first()->checked_at);
    }

    public function test_what_fails_becomes_no_access_with_the_reason_and_the_agency_is_told_once(): void
    {
        $this->renewAgencyToken();
        $this->meta->pageTokens = ['2002' => null]; // a pessoa deixou de ter tarefa na Página
        $lifecycle = app(AgencyAssetLifecycle::class);
        $this->assertSame(['linked' => 1, 'no_access' => 2], $lifecycle->propagateToken($this->agency->id));
        $this->assertSame([
            'ad_account' => ['ligado', null],
            'instagram' => [AgencyAssetLink::NO_ACCESS, AgencyAssetLinkService::NO_TASK_MESSAGE],
            'page' => [AgencyAssetLink::NO_ACCESS, AgencyAssetLinkService::NO_TASK_MESSAGE],
        ], $this->statuses());
        $this->assertSame('token-pagina-2002', SocialConnectionAccount::where('platform', 'facebook')->value('page_access_token'), 'a cópia antiga não muda');
        $this->assertSame(1, Alert::where('company_id', $this->agency->id)->where('title', '2 ativos da Meta ficaram sem acesso')->count());

        $lifecycle->propagateToken($this->agency->id);
        $this->assertSame(1, Alert::where('company_id', $this->agency->id)->count(), 'avisa só na passagem para sem acesso');

        // Uma falha da Meta também conta (com o seu motivo); a seguir, volta a ficar ligado.
        $this->meta->failure = ['ok' => false, 'kind' => 'failed', 'message' => 'Erro'];
        $lifecycle->propagateToken($this->agency->id);
        $this->assertSame([AgencyAssetLink::NO_ACCESS, AgencyAssetLifecycle::PAGE_FAILED], $this->statuses()['page']);
        $this->meta->failure = null;
        $this->meta->pageTokens = ['2002' => 'token-pagina-nova'];
        $lifecycle->propagateToken($this->agency->id);
        $this->assertSame(['ligado', null], $this->statuses()['page']);

        // O ecrã mostra o estado e o motivo de cada ativo, sem tokens.
        CompanyIntegration::where('company_id', $this->client->id)->update(['status' => 'revoked']);
        $lifecycle->propagateToken($this->agency->id);
        $links = collect($this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/agencies/{$this->agency->id}/meta/links")->assertOk()->json('data.links'))->keyBy('asset_type');
        $this->assertSame([AgencyAssetLink::NO_ACCESS, AgencyAssetLifecycle::ADS_MISSING], [$links['ad_account']['status'], $links['ad_account']['message']]);
        $this->assertNotNull($links['page']['checked_at']);
        $this->assertStringNotContainsString('token-', (string) json_encode($links));
    }

    public function test_an_asset_that_leaves_the_portfolio_becomes_no_access_and_comes_back(): void
    {
        $lifecycle = app(AgencyAssetLifecycle::class);
        $this->meta->assets['B2']['ad_accounts'] = [];
        $out = $lifecycle->daily();
        $this->assertSame(['ok' => true, 'no_access' => 1], $out[$this->agency->id]['portfolio']);
        $this->assertSame([AgencyAssetLink::NO_ACCESS, AgencyAssetLifecycle::LEFT_PORTFOLIO], $this->statuses()['ad_account']);
        $this->assertSame(['ligado', null], $this->statuses()['page'], 'os outros continuam ligados');

        // Volta ao portfólio: a verificação seguinte liga-o de novo.
        $this->meta->assets['B2']['ad_accounts'] = [['account_id' => '555', 'name' => 'Anúncios do Restaurante', 'relation' => 'client', 'currency' => 'EUR', 'account_status' => 1]];
        $lifecycle->daily();
        $this->assertSame(['ligado', null], $this->statuses()['ad_account']);

        // Token da agência expirado: todos sem acesso, com esse motivo; uma falha qualquer não muda nada.
        $this->meta->failure = ['ok' => false, 'kind' => 'failed', 'message' => 'Erro'];
        $this->assertSame(['ok' => false, 'no_access' => 0], $lifecycle->checkPortfolio($this->agency->id));
        $this->assertSame(['ligado', null], $this->statuses()['page']);
        $this->meta->failure = ['ok' => false, 'kind' => 'expired', 'message' => 'Expirou'];
        $lifecycle->checkPortfolio($this->agency->id);
        $this->assertSame([[AgencyAssetLink::NO_ACCESS, AgencyAssetLifecycle::AGENCY_EXPIRED]], array_values(array_unique($this->statuses(), SORT_REGULAR)));

        $this->assertContains('agency-meta-assets-daily', config('scheduler.external_job_names'));
        $this->artisan('agency:meta-assets-check')->assertSuccessful();
    }

    public function test_reconnecting_the_agency_meta_queues_the_propagation(): void
    {
        Bus::fake([PropagateAgencyMetaTokenJob::class]);
        $this->social->token = ['token' => 'token-novo', 'expires_at' => CarbonImmutable::now()->addDays(60), 'user_id' => 'U9', 'scopes' => AgencyMetaConnection::SCOPES];
        $out = app(\App\Services\Agency\AgencyMetaConnectionService::class)->handleCallback($this->agency->id, ['user_id' => $this->admin->id], 'ok', null);
        $this->assertSame('agency_ok', $out);
        Bus::assertDispatched(PropagateAgencyMetaTokenJob::class, fn ($j) => $j->agencyId === $this->agency->id);
    }

    public function test_ending_the_relationship_disconnects_the_agency_assets_only_in_xplendor(): void
    {
        $m = CompanyManagement::where('managed_company_id', $this->client->id)->first();
        $m->update(['status' => 'ended', 'active_key' => null, 'ended_at' => now(), 'ended_by_side' => CompanyManagement::SIDE_AGENCY]);
        app(ManagementEndEffects::class)->apply($m->fresh(), false, null);

        $this->assertSame(0, AgencyAssetLink::count());
        $social = SocialConnection::where('company_id', $this->client->id)->first();
        $this->assertSame([SocialConnection::STATUS_REVOKED, null, 0], [$social->status, $social->access_token, $social->accounts()->count()]);
        $this->assertSame('revoked', CompanyIntegration::where('company_id', $this->client->id)->where('platform', 'meta')->value('status'));
        // Nada se revoga na Meta: a agência continua a usar a sua ligação com os outros clientes.
        $this->assertSame([], $this->social->callsTo('revokePermission'));
        $this->assertSame(AgencyMetaConnection::STATUS_ACTIVE, AgencyMetaConnection::where('agency_company_id', $this->agency->id)->value('status'));
        // Não ficam para o cliente escolher (manter ou desligar).
        $this->assertNull($m->fresh()->connections_decision);
    }

    public function test_the_instagram_edges_use_the_names_in_the_meta_documentation(): void
    {
        $this->assertSame(['owned' => 'owned_instagram_accounts', 'client' => 'client_instagram_accounts'], MetaBusinessClient::INSTAGRAM_EDGES);
    }
}
