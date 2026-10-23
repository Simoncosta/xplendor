<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\WarnAgencyMetaRenewalJob;
use App\Models\AgencyMetaConnection;
use App\Models\Alert;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\SocialConnection;
use App\Models\User;
use App\Services\Social\FakeMetaSocialClient;
use App\Services\Social\MetaSocialClientInterface;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Parceiro 2: a ligação da Meta da agência. Uma por agência, feita pelo administrador da agência, com
 * business_management, pages_show_list, pages_read_engagement, instagram_basic e ads_read; token
 * cifrado, utilizador da Meta, permissões e estado; aviso para renovar perto do fim; desligar é a
 * única ação que revoga, e só o que nenhuma outra ligação do mesmo utilizador usa. Meta simulada.
 */
class AgencyMetaConnectionTest extends TestCase
{
    use RefreshDatabase;

    private FakeMetaSocialClient $meta;
    private Company $agency;
    private Company $otherAgency;
    private Company $client;
    private User $admin;
    private User $member;
    private User $otherAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00:00', 'Europe/Lisbon'));
        $this->meta = new FakeMetaSocialClient();
        $this->app->instance(MetaSocialClientInterface::class, $this->meta);
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 9, 'created_at' => now(), 'updated_at' => now()]);
        $make = fn (string $nipc, string $name) => Company::create(['nipc' => $nipc, 'fiscal_name' => $name, 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->agency = $make('500400001', 'Agência Norte Lda');
        $this->otherAgency = $make('500400002', 'Agência Sul Lda');
        foreach ([$this->agency, $this->otherAgency] as $a) {
            $a->forceFill(['agency_enabled_at' => now()])->save();
        }
        $this->client = $make('500400003', 'Cliente Lda');
        $this->admin = User::factory()->create(['company_id' => $this->agency->id, 'role' => 'admin', 'name' => 'Ana Admin']);
        $this->member = User::factory()->create(['company_id' => $this->agency->id, 'role' => 'user']);
        $this->otherAdmin = User::factory()->create(['company_id' => $this->otherAgency->id, 'role' => 'admin']);
    }

    private function as(User $u): self
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u, 'sanctum');
    }

    private function url(string $path = '', ?Company $agency = null): string
    {
        return '/api/v1/agencies/' . ($agency ?? $this->agency)->id . '/meta' . $path;
    }

    /** Autoriza pela rota e volta pelo callback das redes. */
    private function connect(array $scopes, string $user = 'U9', ?string $expires = '2027-01-09 10:00:00'): string
    {
        $url = (string) $this->as($this->admin)->getJson($this->url('/auth-url'))->assertOk()->json('data.url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame(['business_management', 'ads_read'], $this->meta->callsTo('authUrl')[0][1], 'as das redes vão sempre; estas juntam-se');
        $this->meta->token = ['token' => 'token-agencia', 'expires_at' => $expires ? CarbonImmutable::parse($expires) : null, 'user_id' => $user, 'scopes' => $scopes];

        return (string) $this->get('/api/oauth/meta/social/callback?state=' . urlencode((string) $q['state']) . '&code=ok')->headers->get('Location');
    }

    public function test_the_agency_admin_connects_and_the_token_is_stored_encrypted_and_never_shown(): void
    {
        $this->as($this->member)->getJson($this->url())->assertOk()->assertJsonPath('data.connected', false);

        $location = $this->connect(AgencyMetaConnection::SCOPES);
        $this->assertStringEndsWith('/agency?tab=meta&meta=ok', $location);

        $c = AgencyMetaConnection::sole();
        $this->assertSame([$this->agency->id, 'U9', 'token-agencia', AgencyMetaConnection::STATUS_ACTIVE, $this->admin->id],
            [$c->agency_company_id, $c->meta_user_id, $c->access_token, $c->status, $c->connected_by_user_id]);
        $this->assertNotSame('token-agencia', DB::table('agency_meta_connections')->value('access_token'), 'cifrado na base de dados');
        $this->assertEqualsCanonicalizing(AgencyMetaConnection::SCOPES, $c->granted_scopes);

        $res = $this->as($this->member)->getJson($this->url())->assertOk();
        $res->assertJsonPath('data.connected', true)->assertJsonPath('data.meta_user_id', 'U9')->assertJsonPath('data.connected_by', 'Ana Admin')
            ->assertJsonPath('data.days_left', 60)->assertJsonPath('data.renew_soon', false)->assertJsonPath('data.missing_scopes', []);
        $this->assertStringNotContainsString('token-agencia', (string) $res->getContent());

        // Voltar a ligar substitui a ligação (uma por agência).
        $this->connect(AgencyMetaConnection::SCOPES, 'U9', '2027-02-01 10:00:00');
        $this->assertSame(1, AgencyMetaConnection::count());
    }

    public function test_without_every_permission_nothing_is_stored_nor_revoked(): void
    {
        $location = $this->connect(['pages_show_list', 'pages_read_engagement', 'instagram_basic', 'ads_read']);
        $this->assertStringEndsWith('/agency?tab=meta&meta=error&reason=scopes', $location);
        $this->assertSame(0, AgencyMetaConnection::count());
        $this->assertSame([], $this->meta->callsTo('revokePermission'));
    }

    public function test_only_the_agency_admin_connects_and_disconnects_and_other_agencies_get_403(): void
    {
        AgencyMetaConnection::create(['agency_company_id' => $this->agency->id, 'meta_user_id' => 'U9', 'access_token' => 't', 'granted_scopes' => AgencyMetaConnection::SCOPES]);
        $this->as($this->member)->getJson($this->url('/auth-url'))->assertStatus(403);
        $this->as($this->member)->deleteJson($this->url())->assertStatus(403);
        foreach (['getJson' => '', 'deleteJson' => ''] as $method => $path) {
            $this->as($this->otherAdmin)->{$method}($this->url($path))->assertStatus(403);
        }
        $this->as($this->otherAdmin)->getJson($this->url('/auth-url'))->assertStatus(403);
        $clientAdmin = User::factory()->create(['company_id' => $this->client->id, 'role' => 'admin']);
        $this->as($clientAdmin)->getJson($this->url())->assertStatus(403);
        $this->as($this->admin)->getJson($this->url('', $this->otherAgency))->assertStatus(403);
        $this->assertSame(1, AgencyMetaConnection::count());
    }

    public function test_disconnecting_revokes_only_what_no_other_connection_of_the_same_meta_user_uses(): void
    {
        $this->connect(AgencyMetaConnection::SCOPES, 'U9');
        // O mesmo utilizador da Meta liga as redes de um cliente: as permissões das redes ficam.
        SocialConnection::create(['company_id' => $this->client->id, 'meta_user_id' => 'U9', 'access_token' => 'leitura',
            'granted_scopes' => SocialConnection::SCOPES, 'status' => SocialConnection::STATUS_ACTIVE, 'connected_at' => now()]);

        $res = $this->as($this->admin)->deleteJson($this->url())->assertOk();
        $this->assertSame(['business_management', 'ads_read'], $res->json('data.revoked'));
        $this->assertEqualsCanonicalizing(['pages_show_list', 'pages_read_engagement', 'instagram_basic'], $res->json('data.kept_shared'));
        $this->assertSame([['token-agencia', 'business_management'], ['token-agencia', 'ads_read']], $this->meta->callsTo('revokePermission'));
        $this->assertSame(0, AgencyMetaConnection::count(), 'o token apaga-se na XPLENDOR');
        $res->assertJsonPath('data.meta.connected', false);
    }

    public function test_disconnecting_a_client_never_cuts_the_agency_connection(): void
    {
        $this->connect(AgencyMetaConnection::SCOPES, 'U9');
        $ads = CompanyIntegration::create(['company_id' => $this->client->id, 'platform' => 'meta', 'status' => 'active', 'access_token' => 'anuncios', 'meta_user_id' => 'U9']);
        $social = SocialConnection::create(['company_id' => $this->client->id, 'meta_user_id' => 'U9', 'access_token' => 'leitura',
            'granted_scopes' => SocialConnection::SCOPES, 'status' => SocialConnection::STATUS_ACTIVE, 'connected_at' => now()]);
        $this->assertFalse(\App\Services\Integrations\SharedMetaUser::canRevokeAds($ads), 'a agência usa o ads_read do mesmo utilizador');
        $this->assertFalse(\App\Services\Integrations\SharedMetaUser::canRevokeSocial($social), 'a agência usa as permissões das redes');
        $this->assertFalse(\App\Services\Integrations\SharedMetaUser::canRevokeNewSocialGrant('U9'));
    }

    public function test_the_agency_is_warned_once_before_the_end_and_the_connection_expires(): void
    {
        $this->connect(AgencyMetaConnection::SCOPES, 'U9', '2026-11-18 10:00:00');
        $this->as($this->admin)->getJson($this->url())->assertJsonPath('data.renew_soon', true)->assertJsonPath('data.days_left', 8);

        (new WarnAgencyMetaRenewalJob())->handle(app(\App\Services\Agency\AgencyMetaConnectionService::class));
        (new WarnAgencyMetaRenewalJob())->handle(app(\App\Services\Agency\AgencyMetaConnectionService::class));
        $alerts = Alert::where('company_id', $this->agency->id)->where('title', 'Renovar a ligação da Meta da agência')->get();
        $this->assertCount(1, $alerts, 'um só aviso');
        $this->assertSame('/agency?tab=meta', $alerts[0]->detail_path);

        $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00:00', 'Europe/Lisbon'));
        (new WarnAgencyMetaRenewalJob())->handle(app(\App\Services\Agency\AgencyMetaConnectionService::class));
        $this->assertSame(AgencyMetaConnection::STATUS_EXPIRED, AgencyMetaConnection::sole()->status);
        $this->assertSame(1, Alert::where('company_id', $this->agency->id)->where('title', 'Ligação da Meta da agência expirou')->count());
        $this->assertSame([], $this->meta->callsTo('revokePermission'));
    }
}
