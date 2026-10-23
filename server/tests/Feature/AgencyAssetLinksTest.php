<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ReadSocialFollowersJob;
use App\Models\AgencyAssetLink;
use App\Models\AgencyMetaConnection;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\CompanyManagement;
use App\Models\SocialConnection;
use App\Models\SocialConnectionAccount;
use App\Models\User;
use App\Services\Agency\Meta\FakeMetaBusinessClient;
use App\Services\Agency\Meta\MetaBusinessClient;
use App\Services\Agency\Meta\MetaBusinessClientInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Parceiro 4: ligar os ativos da Meta da agência a uma empresa gerida. Só clientes ativos desta agência
 * (qualquer outra empresa: 403); cada ativo só numa empresa; escreve nas tabelas de sempre com a origem
 * "agencia" (Página e Instagram com o token da Página; conta de anúncios na company_integrations); sem
 * tarefa na Página, fica um estado claro; a ligação própria de uma empresa só passa para a agência a
 * pedido. Cliente da Meta simulado (nenhum pedido real).
 */
class AgencyAssetLinksTest extends TestCase
{
    use RefreshDatabase;

    private FakeMetaBusinessClient $meta;
    private Company $agency;
    private Company $otherAgency;
    private Company $client;
    private Company $notClient;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Bus::fake([ReadSocialFollowersJob::class, \App\Jobs\SyncMetaAccountInsightsJob::class, \App\Jobs\SyncMetaAdInsightsJob::class]);
        $this->meta = new FakeMetaBusinessClient();
        $this->app->instance(MetaBusinessClientInterface::class, $this->meta);
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 9, 'created_at' => now(), 'updated_at' => now()]);
        $make = fn (string $nipc, string $name) => Company::create(['nipc' => $nipc, 'fiscal_name' => $name, 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->agency = $make('500400101', 'Agência Norte Lda');
        $this->otherAgency = $make('500400102', 'Agência Sul Lda');
        foreach ([$this->agency, $this->otherAgency] as $a) {
            $a->forceFill(['agency_enabled_at' => now()])->save();
        }
        $this->client = $make('500400103', 'Restaurante Cliente Lda');
        $this->notClient = $make('500400104', 'Outra Empresa Lda');
        CompanyManagement::create(['agency_company_id' => $this->agency->id, 'managed_company_id' => $this->client->id, 'origin' => 'platform',
            'status' => 'active', 'active_key' => $this->client->id, 'team_scope' => 'all', 'requested_at' => now(), 'responded_at' => now()]);
        $this->admin = User::factory()->create(['company_id' => $this->agency->id, 'role' => 'admin']);
        AgencyMetaConnection::create(['agency_company_id' => $this->agency->id, 'meta_user_id' => 'U9', 'access_token' => 'token-agencia',
            'granted_scopes' => AgencyMetaConnection::SCOPES, 'status' => AgencyMetaConnection::STATUS_ACTIVE]);
        $this->meta->businesses = [['id' => 'B2', 'name' => 'Restaurante Cliente', 'verification_status' => 'not_verified']];
        $this->meta->assets = ['B2' => [
            'pages' => [['id' => '2002', 'name' => 'Restaurante', 'relation' => 'client', 'instagram' => ['id' => '17842', 'username' => 'restaurante']],
                ['id' => '2003', 'name' => 'Sem tarefa', 'relation' => 'client', 'instagram' => null]],
            'ad_accounts' => [['account_id' => '555', 'name' => 'Anúncios do Restaurante', 'relation' => 'client', 'currency' => 'EUR', 'account_status' => 1]],
            'instagram' => [['id' => '17842', 'username' => 'restaurante', 'relation' => 'client']],
        ]];
        $this->meta->pageTokens = ['2002' => 'token-pagina-2002', '2003' => null];
    }

    private function as(User $u): self
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u, 'sanctum');
    }

    private function url(string $path = '', ?Company $agency = null): string
    {
        return '/api/v1/agencies/' . ($agency ?? $this->agency)->id . '/meta/links' . $path;
    }

    private function link(string $type, string $id, ?Company $company = null, array $extra = [])
    {
        return $this->as($this->admin)->postJson($this->url(), ['company_id' => ($company ?? $this->client)->id, 'asset_type' => $type, 'external_id' => $id] + $extra);
    }

    public function test_a_page_and_its_instagram_are_written_with_the_agency_origin_and_the_page_token(): void
    {
        $this->link('page', '2002')->assertOk()->assertJsonPath('data.status', 'ligado')->assertJsonPath('data.company', 'Restaurante Cliente Lda');
        $this->link('instagram', '17842')->assertOk()->assertJsonPath('data.status', 'ligado');

        $connection = SocialConnection::where('company_id', $this->client->id)->first();
        $this->assertSame(['agencia', 'U9', 'token-agencia', SocialConnection::STATUS_ACTIVE], [$connection->origin, $connection->meta_user_id, $connection->access_token, $connection->status]);
        $accounts = SocialConnectionAccount::where('social_connection_id', $connection->id)->orderBy('platform')->get();
        $this->assertSame([['facebook', '2002', '2002', 'token-pagina-2002', true], ['instagram', '17842', '2002', 'token-pagina-2002', true]],
            $accounts->map(fn ($a) => [$a->platform, $a->external_id, $a->page_id, $a->page_access_token, (bool) $a->is_primary])->all());
        $this->assertSame([['pageToken', ['token-agencia', '2002']], ['pageToken', ['token-agencia', '2002']]],
            array_values(array_filter($this->meta->calls, fn ($c) => $c[0] === 'pageToken')));
        Bus::assertDispatched(ReadSocialFollowersJob::class);

        $list = $this->as($this->admin)->getJson($this->url())->assertOk()->json('data');
        $this->assertSame([['id' => $this->client->id, 'name' => 'Restaurante Cliente Lda']], $list['clients']);
        $this->assertCount(2, $list['links']);
        $this->assertStringNotContainsString('token-', (string) json_encode($list));
    }

    public function test_an_ad_account_goes_to_the_company_integration_for_the_ads_metrics(): void
    {
        $this->link('ad_account', '555')->assertOk()->assertJsonPath('data.status', 'ligado');
        $integration = CompanyIntegration::where('company_id', $this->client->id)->where('platform', 'meta')->first();
        $this->assertSame(['555', 'token-agencia', 'active', 'agencia', 'U9'], [$integration->account_id, (string) $integration->access_token, $integration->status, $integration->origin, $integration->meta_user_id]);
        // As métricas de anúncios pedem-se como numa ligação da própria empresa (na fila).
        Bus::assertDispatched(\App\Jobs\SyncMetaAccountInsightsJob::class);
        Bus::assertDispatched(\App\Jobs\SyncMetaAdInsightsJob::class);
    }

    public function test_without_a_task_on_the_page_the_link_stays_with_a_clear_state_and_nothing_is_written(): void
    {
        $this->link('page', '2003')->assertOk()->assertJsonPath('data.status', 'sem_tarefa')
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'atribua uma tarefa na Página'));
        $this->assertSame(0, SocialConnection::count());
        $this->assertSame('sem_tarefa', AgencyAssetLink::first()->status);
    }

    public function test_each_asset_only_in_one_company_and_only_active_clients_of_this_agency(): void
    {
        $other = Company::create(['nipc' => '500400105', 'fiscal_name' => 'Segundo Cliente Lda', 'plan_id' => $this->client->plan_id, 'subscription_status' => 'active']);
        CompanyManagement::create(['agency_company_id' => $this->agency->id, 'managed_company_id' => $other->id, 'origin' => 'platform',
            'status' => 'active', 'active_key' => $other->id, 'team_scope' => 'all', 'requested_at' => now(), 'responded_at' => now()]);
        $this->link('ad_account', '555')->assertOk();
        $this->link('ad_account', '555', $other)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'já está ligado a Restaurante Cliente Lda'));

        // Qualquer empresa que não seja cliente ativo desta agência: 403.
        $this->link('page', '2002', $this->notClient)->assertStatus(403);
        $this->assertSame(0, SocialConnection::where('company_id', $this->notClient->id)->count());
        // Outra agência e um membro sem ser administrador: 403.
        $this->as(User::factory()->create(['company_id' => $this->otherAgency->id, 'role' => 'admin']))
            ->postJson($this->url('', $this->agency), ['company_id' => $this->client->id, 'asset_type' => 'page', 'external_id' => '2002'])->assertStatus(403);
        $this->as(User::factory()->create(['company_id' => $this->agency->id, 'role' => 'user']))
            ->postJson($this->url(), ['company_id' => $this->client->id, 'asset_type' => 'page', 'external_id' => '2002'])->assertStatus(403);
        $this->as(User::factory()->create(['company_id' => $this->otherAgency->id, 'role' => 'admin']))->getJson($this->url('', $this->agency))->assertStatus(403);
        // Um ativo que a agência não vê.
        $this->link('page', '9999')->assertStatus(422);
    }

    public function test_the_company_own_connection_stays_until_asked_to_move_to_the_agency(): void
    {
        $own = SocialConnection::forceCreate(['company_id' => $this->client->id, 'meta_user_id' => 'SIMON', 'access_token' => 'token-proprio', 'status' => SocialConnection::STATUS_ACTIVE]);
        SocialConnectionAccount::create(['company_id' => $this->client->id, 'social_connection_id' => $own->id, 'platform' => 'facebook', 'external_id' => '2002',
            'page_id' => '2002', 'name' => 'Restaurante', 'page_access_token' => 'pagina-antiga', 'is_primary' => true]);
        $this->link('page', '2002')->assertStatus(409)->assertJsonPath('message', fn ($m) => str_contains($m, 'própria ligação'));
        $this->assertSame('token-proprio', (string) $own->fresh()->access_token);

        $this->link('page', '2002', null, ['replace' => true])->assertOk();
        $own->refresh();
        $this->assertSame(['agencia', 'U9', 'token-agencia'], [$own->origin, $own->meta_user_id, (string) $own->access_token]);
        $this->assertSame(['token-pagina-2002'], SocialConnectionAccount::where('social_connection_id', $own->id)->pluck('page_access_token')->map(fn ($t) => (string) $t)->all());
    }

    public function test_unlink_removes_only_what_the_agency_wrote(): void
    {
        $this->link('page', '2002')->assertOk();
        $this->link('ad_account', '555')->assertOk();
        foreach (AgencyAssetLink::all() as $l) {
            $this->as($this->admin)->deleteJson($this->url("/{$l->id}"))->assertOk();
        }
        $this->assertSame(0, AgencyAssetLink::count());
        $this->assertSame(0, SocialConnectionAccount::count());
        $this->assertSame('revoked', CompanyIntegration::where('company_id', $this->client->id)->value('status'));
        $this->as($this->admin)->deleteJson($this->url('/999'))->assertNotFound();
    }

    public function test_the_real_client_asks_the_page_token_with_the_agency_user_token(): void
    {
        Http::fake(['graph.facebook.com/*' => fn (Request $r) => str_contains($r->url(), '/2002')
            ? Http::response(['id' => '2002', 'access_token' => 'tp'])
            : Http::response(['id' => '2003'])]);
        $client = new MetaBusinessClient();
        $this->assertSame(['ok' => true, 'access_token' => 'tp'], $client->pageToken('token-agencia', '2002'));
        $this->assertSame(['ok' => true, 'access_token' => null], $client->pageToken('token-agencia', '2003'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/2002?') && str_contains($r->url(), 'fields=access_token') && str_contains($r->url(), 'access_token=token-agencia'));
    }
}
