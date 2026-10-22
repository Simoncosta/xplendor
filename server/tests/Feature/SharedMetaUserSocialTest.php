<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\SocialConnection;
use App\Services\Social\FakeMetaSocialClient;
use App\Services\Social\MetaSocialClient;
use App\Services\Social\MetaSocialClientInterface;
use App\Services\Social\SocialConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Redes sociais com o mesmo utilizador da Meta em várias empresas: desligar uma só desliga na
 * XPLENDOR; a permissão só se revoga na última. Usa o cliente simulado (nunca chama a Meta).
 */
class SharedMetaUserSocialTest extends TestCase
{
    use RefreshDatabase;

    private FakeMetaSocialClient $meta;

    private int $plan;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->meta = new FakeMetaSocialClient();
        $this->app->instance(MetaSocialClientInterface::class, $this->meta);
        $this->plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function company(string $nipc): Company
    {
        return Company::create(['nipc' => $nipc, 'fiscal_name' => "Empresa {$nipc}", 'plan_id' => $this->plan, 'subscription_status' => 'active']);
    }

    private function connect(Company $company, ?string $metaUserId, string $token): SocialConnection
    {
        return SocialConnection::create(['company_id' => $company->id, 'meta_user_id' => $metaUserId, 'access_token' => $token,
            'granted_scopes' => SocialConnection::SCOPES, 'status' => SocialConnection::STATUS_ACTIVE, 'connected_at' => now()]);
    }

    public function test_the_interface_is_bound_to_the_real_client_outside_the_tests(): void
    {
        // Sem a instância simulada do setUp, vale a ligação do AppServiceProvider.
        $this->app->forgetInstance(MetaSocialClientInterface::class);
        $this->assertInstanceOf(MetaSocialClient::class, app(MetaSocialClientInterface::class));
    }

    public function test_disconnecting_one_company_keeps_the_other_with_the_same_meta_user(): void
    {
        $a = $this->company('500019661');
        $b = $this->company('500019662');
        $this->connect($a, 'U1', 'tok-a');
        $this->connect($b, 'U1', 'tok-b');

        $result = app(SocialConnectionService::class)->disconnect($a->id, false);
        $this->assertSame([false, true], [$result['permissions_revoked'], $result['permission_kept_shared']]);
        $this->assertSame([], $this->meta->callsTo('revokePermission'), 'nada revogado na Meta');
        $this->assertSame(SocialConnection::STATUS_REVOKED, SocialConnection::where('company_id', $a->id)->value('status'));
        $this->assertSame(SocialConnection::STATUS_ACTIVE, SocialConnection::where('company_id', $b->id)->value('status'));
        $this->assertSame('tok-b', SocialConnection::where('company_id', $b->id)->sole()->access_token);

        // A última empresa desse utilizador revoga as três permissões, uma a uma.
        $result = app(SocialConnectionService::class)->disconnect($b->id, false);
        $this->assertTrue($result['permissions_revoked']);
        $this->assertSame(array_map(fn ($p) => ['tok-b', $p], SocialConnection::SCOPES), $this->meta->callsTo('revokePermission'));
    }

    public function test_different_meta_users_are_revoked_as_before_and_unknown_ones_are_kept(): void
    {
        $a = $this->company('500019663');
        $b = $this->company('500019664');
        $this->connect($a, 'U1', 'tok-a');
        $this->connect($b, 'U2', 'tok-b');
        app(SocialConnectionService::class)->disconnect($a->id, false);
        $this->assertCount(3, $this->meta->callsTo('revokePermission'));

        $c = $this->company('500019665');
        $this->connect($c, null, 'tok-c');
        $result = app(SocialConnectionService::class)->disconnect($b->id, false);
        $this->assertTrue($result['permission_kept_shared'], 'com uma ligação antiga de utilizador desconhecido, não se arrisca');
        $this->assertCount(3, $this->meta->callsTo('revokePermission'));
    }

    public function test_a_new_grant_with_missing_permissions_is_not_revoked_when_the_user_is_connected_elsewhere(): void
    {
        $a = $this->company('500019666');
        $c = $this->company('500019667');
        $this->connect($a, 'U1', 'tok-a');
        $this->meta->token = ['token' => 'tok-novo', 'expires_at' => null, 'user_id' => 'U1', 'scopes' => ['pages_show_list']];
        $this->meta->permissions = ['pages_show_list' => 'granted', 'pages_read_engagement' => 'declined', 'instagram_basic' => 'declined'];

        Cache::put(SocialConnectionService::stateKey('n1'), ['company_id' => $c->id, 'user_id' => null], now()->addMinutes(5));
        [, $signal] = app(SocialConnectionService::class)->handleCallback('n1', 'code', null);
        $this->assertSame('error:scopes', $signal);
        $this->assertSame([], $this->meta->callsTo('revokePermission'), 'revogar cortava a ligação da empresa A');
        $this->assertSame(SocialConnection::STATUS_ACTIVE, SocialConnection::where('company_id', $a->id)->value('status'));

        // Um utilizador sem outras ligações: as concedidas são retiradas, como antes.
        $this->meta->token['user_id'] = 'U9';
        Cache::put(SocialConnectionService::stateKey('n2'), ['company_id' => $c->id, 'user_id' => null], now()->addMinutes(5));
        app(SocialConnectionService::class)->handleCallback('n2', 'code', null);
        $this->assertSame([['tok-novo', 'pages_show_list']], $this->meta->callsTo('revokePermission'));
    }
}
