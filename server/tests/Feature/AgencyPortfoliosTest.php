<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AgencyMetaConnection;
use App\Models\Company;
use App\Models\User;
use App\Services\Agency\Meta\FakeMetaBusinessClient;
use App\Services\Agency\Meta\MetaBusinessClient;
use App\Services\Agency\Meta\MetaBusinessClientInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Parceiro 3: na área da agência, os portfólios da Meta (GET /me/businesses) e os ativos de cada um
 * (Páginas com o Instagram, contas de anúncios e contas de Instagram), da agência e dos clientes. Só
 * leitura; só o administrador da agência (e o root); cliente da Meta simulado.
 */
class AgencyPortfoliosTest extends TestCase
{
    use RefreshDatabase;

    private FakeMetaBusinessClient $meta;
    private Company $agency;
    private Company $otherAgency;
    private User $admin;
    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->meta = new FakeMetaBusinessClient();
        $this->app->instance(MetaBusinessClientInterface::class, $this->meta);
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 9, 'created_at' => now(), 'updated_at' => now()]);
        $make = fn (string $nipc, string $name) => Company::create(['nipc' => $nipc, 'fiscal_name' => $name, 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->agency = $make('500400101', 'Agência Norte Lda');
        $this->otherAgency = $make('500400102', 'Agência Sul Lda');
        foreach ([$this->agency, $this->otherAgency] as $a) {
            $a->forceFill(['agency_enabled_at' => now()])->save();
        }
        $this->admin = User::factory()->create(['company_id' => $this->agency->id, 'role' => 'admin']);
        $this->member = User::factory()->create(['company_id' => $this->agency->id, 'role' => 'user']);
        $this->meta->businesses = [['id' => 'B1', 'name' => 'Agência Norte', 'verification_status' => 'verified'], ['id' => 'B2', 'name' => 'Restaurante Cliente', 'verification_status' => 'not_verified']];
        $this->meta->assets = [
            'B1' => ['pages' => [['id' => '1001', 'name' => 'Agência Norte', 'relation' => 'owned', 'instagram' => null]], 'ad_accounts' => [], 'instagram' => []],
            'B2' => [
                'pages' => [['id' => '2002', 'name' => 'Restaurante', 'relation' => 'client', 'instagram' => ['id' => '17842', 'username' => 'restaurante']]],
                'ad_accounts' => [['account_id' => '555', 'name' => 'Anúncios do Restaurante', 'relation' => 'client', 'currency' => 'EUR', 'account_status' => 1]],
                'instagram' => [['id' => '17842', 'username' => 'restaurante', 'relation' => 'client']],
            ],
        ];
    }

    private function as(User $u): self
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u, 'sanctum');
    }

    private function url(?Company $agency = null, string $q = ''): string
    {
        return '/api/v1/agencies/' . ($agency ?? $this->agency)->id . '/meta/portfolios' . $q;
    }

    private function connect(): AgencyMetaConnection
    {
        return AgencyMetaConnection::create(['agency_company_id' => $this->agency->id, 'meta_user_id' => 'U9', 'access_token' => 'token-agencia',
            'granted_scopes' => AgencyMetaConnection::SCOPES, 'status' => AgencyMetaConnection::STATUS_ACTIVE]);
    }

    public function test_the_agency_admin_sees_each_portfolio_with_its_owned_and_client_assets(): void
    {
        $this->as($this->admin)->getJson($this->url())->assertOk()->assertJsonPath('data.connected', false)->assertJsonPath('data.businesses', []);
        $this->connect();

        $res = $this->as($this->admin)->getJson($this->url())->assertOk();
        $data = $res->json('data');
        $this->assertTrue($data['ok']);
        $this->assertSame(['B1', 'B2'], array_column($data['businesses'], 'id'));
        $client = $data['businesses'][1];
        $this->assertSame('not_verified', $client['verification_status']);
        $this->assertSame(['client', 'restaurante'], [$client['pages'][0]['relation'], $client['pages'][0]['instagram']['username']]);
        $this->assertSame(['555', 'EUR', 1], [$client['ad_accounts'][0]['account_id'], $client['ad_accounts'][0]['currency'], $client['ad_accounts'][0]['account_status']]);
        $this->assertSame('17842', $client['instagram'][0]['id']);
        $this->assertStringNotContainsString('token-agencia', (string) $res->getContent());
        $this->assertSame([['token-agencia'], ['token-agencia', 'B1'], ['token-agencia', 'B2']], array_column($this->meta->calls, 1));

        // Guardado 10 minutos; "Atualizar" lê de novo. Nada é ligado a nenhuma empresa (só leitura).
        $this->as($this->admin)->getJson($this->url())->assertOk();
        $this->assertCount(3, $this->meta->calls);
        $this->as($this->admin)->getJson($this->url(null, '?refresh=1'))->assertOk();
        $this->assertCount(6, $this->meta->calls);
    }

    public function test_only_the_agency_admin_and_never_another_agency(): void
    {
        $this->connect();
        $this->as($this->member)->getJson($this->url())->assertStatus(403);
        $this->as(User::factory()->create(['company_id' => $this->otherAgency->id, 'role' => 'admin']))->getJson($this->url())->assertStatus(403);
        $this->as($this->admin)->getJson($this->url($this->otherAgency))->assertStatus(403);
        $this->as(User::factory()->create(['company_id' => $this->otherAgency->id, 'role' => 'root']))->getJson($this->url())->assertOk();
        $this->assertCount(3, $this->meta->calls, 'só o root leu');
    }

    public function test_an_expired_token_marks_the_connection_and_asks_to_reconnect(): void
    {
        $connection = $this->connect();
        $this->meta->failure = ['ok' => false, 'kind' => 'expired', 'message' => 'Error validating access token'];
        $this->as($this->admin)->getJson($this->url())->assertOk()->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.error', 'O acesso da agência à Meta expirou. Um administrador da agência tem de voltar a ligar.');
        $this->assertSame(AgencyMetaConnection::STATUS_EXPIRED, $connection->fresh()->status);

        $this->meta->failure = null;
        $this->as($this->admin)->getJson($this->url())->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame(AgencyMetaConnection::STATUS_ACTIVE, $connection->fresh()->status, 'uma falha não fica guardada');
    }

    public function test_the_real_client_reads_the_documented_edges_and_follows_paging(): void
    {
        $graph = MetaBusinessClient::class;
        Http::fake(function (Request $r) {
            $path = (string) parse_url($r->url(), PHP_URL_PATH);
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

            return match (true) {
                str_ends_with($path, '/me/businesses') && ! isset($q['after']) => Http::response(['data' => [['id' => 'B1', 'name' => 'Um', 'verification_status' => 'verified']],
                    'paging' => ['next' => 'https://graph.facebook.com/v25.0/me/businesses?after=x&access_token=t']]),
                str_ends_with($path, '/me/businesses') => Http::response(['data' => [['id' => 'B2', 'name' => 'Dois']]]),
                str_ends_with($path, '/B1/client_pages') => Http::response(['data' => [['id' => '2002', 'name' => 'Página', 'instagram_business_account' => ['id' => '17842', 'username' => 'pagina']]]]),
                str_ends_with($path, '/B1/client_ad_accounts') => Http::response(['data' => [['account_id' => '555', 'name' => 'Conta', 'currency' => 'EUR', 'account_status' => 2]]]),
                default => Http::response(['data' => []]),
            };
        });
        $client = new $graph();
        $this->assertSame(['B1', 'B2'], array_column($client->businesses('t')['businesses'], 'id'));
        $assets = $client->assets('t', 'B1');
        $this->assertSame([['id' => '2002', 'name' => 'Página', 'relation' => 'client', 'instagram' => ['id' => '17842', 'username' => 'pagina']]], $assets['pages']);
        $this->assertSame(2, $assets['ad_accounts'][0]['account_status']);

        $paths = [];
        Http::assertSent(function (Request $r) use (&$paths) {
            $paths[] = (string) parse_url($r->url(), PHP_URL_PATH);

            return true;
        });
        foreach (['/B1/owned_pages', '/B1/client_pages', '/B1/owned_ad_accounts', '/B1/client_ad_accounts', '/B1/owned_instagram_accounts', '/B1/client_instagram_accounts'] as $edge) {
            $this->assertContains('/v25.0' . $edge, $paths);
        }
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/me/businesses') && str_contains(urldecode($r->url()), 'fields=id,name,verification_status'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/B1/owned_pages') && str_contains(urldecode($r->url()), 'fields=id,name,instagram_business_account{id,username}'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/B1/client_ad_accounts') && str_contains(urldecode($r->url()), 'fields=account_id,name,currency,account_status'));
    }
}
