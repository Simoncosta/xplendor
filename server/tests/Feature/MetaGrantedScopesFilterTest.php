<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AgencyMetaConnection;
use App\Models\Company;
use App\Models\SocialConnection;
use App\Models\SocialConnectionAccount;
use App\Models\SocialPublishingGrant;
use App\Models\User;
use App\Services\Social\MetaSocialClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * As permissões que a Meta devolve no debug_token passam pelo filtro verdadeiro do MetaSocialClient
 * (sem o FakeMetaSocialClient, que o saltava). Antes, o filtro guardava só as três de leitura e deitava
 * fora as da publicação e da agência: com a Meta real, "Ligar publicação" dava sempre publish_scopes e a
 * agência dava sempre scopes. Os três fluxos, do início pela rota até ao callback; Meta simulada (Http::fake).
 */
class MetaGrantedScopesFilterTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        config([
            'services.meta.app_id' => '111', 'services.meta.app_secret' => 'segredo',
            'services.meta.redirect_uri' => 'https://dev.exemplo.pt/api/oauth/meta/callback',
            'services.meta.social_redirect_uri' => null,
        ]);
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500095891', 'fiscal_name' => 'Quebom Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
    }

    /** A Graph API simulada: troca do code, debug_token com as permissões indicadas e a lista de Páginas. */
    private function fakeMeta(array $scopes): void
    {
        Http::fake(function (HttpRequest $r) use ($scopes) {
            $path = (string) parse_url($r->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/oauth/access_token')) {
                return Http::response(['access_token' => 'token-longo', 'token_type' => 'bearer']);
            }
            if (str_ends_with($path, '/debug_token')) {
                return Http::response(['data' => ['user_id' => '7001', 'is_valid' => true, 'expires_at' => 0, 'scopes' => $scopes]]);
            }
            if (str_ends_with($path, '/me/accounts')) {
                return Http::response(['data' => [['id' => '1001', 'name' => 'Quebom', 'access_token' => 'pagina-publicar',
                    'instagram_business_account' => ['id' => '17841', 'username' => 'quebom', 'name' => 'Quebom']]]]);
            }

            return Http::response(['error' => ['message' => 'pedido inesperado', 'code' => 100]], 400);
        });
    }

    /** Começa pela rota indicada e volta pelo callback das redes; devolve o Location. */
    private function authorize(string $authPath): string
    {
        $url = (string) $this->actingAs($this->admin, 'sanctum')->getJson($authPath)->assertOk()->json('data.url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->app['auth']->forgetGuards();

        return (string) $this->get('/api/oauth/meta/social/callback?code=abc&state=' . urlencode((string) $q['state']))->headers->get('Location');
    }

    public function test_the_filter_keeps_the_permissions_of_every_flow_and_drops_the_rest(): void
    {
        $this->fakeMeta([...SocialConnection::SCOPES, ...SocialPublishingGrant::SCOPES, ...AgencyMetaConnection::EXTRA_SCOPES, 'email', 'public_profile']);

        $token = app(MetaSocialClient::class)->exchangeCode('abc');

        $this->assertEqualsCanonicalizing(
            [...SocialConnection::SCOPES, ...SocialPublishingGrant::SCOPES, ...AgencyMetaConnection::EXTRA_SCOPES],
            $token['scopes'],
            'ficam as de leitura, as de publicar e as da agência; as que a XPLENDOR não usa saem'
        );
    }

    public function test_social_flow_connects_and_stores_only_the_read_permissions(): void
    {
        // Um utilizador que já tinha dado tudo (publicar e agência) noutra ligação.
        $this->fakeMeta([...SocialConnection::SCOPES, ...SocialPublishingGrant::SCOPES, ...AgencyMetaConnection::EXTRA_SCOPES]);

        $location = $this->authorize("/api/v1/companies/{$this->company->id}/integrations/social/auth-url");

        $this->assertStringEndsWith("/companies/{$this->company->id}?social=choose", $location);
        $connection = SocialConnection::where('company_id', $this->company->id)->sole();
        $this->assertSame(['7001', SocialConnection::STATUS_PENDING_SELECTION], [$connection->meta_user_id, $connection->status]);
        $this->assertEqualsCanonicalizing(SocialConnection::SCOPES, $connection->granted_scopes, 'a leitura guarda só as suas três');
    }

    public function test_social_flow_without_a_read_permission_still_refuses(): void
    {
        $this->fakeMeta(['pages_show_list', ...SocialPublishingGrant::SCOPES]);

        $location = $this->authorize("/api/v1/companies/{$this->company->id}/integrations/social/auth-url");

        $this->assertStringContainsString('social=error', $location);
        $this->assertSame(0, SocialConnection::where('company_id', $this->company->id)->whereNotNull('access_token')->count());
    }

    public function test_publishing_flow_connects_with_the_real_filter(): void
    {
        $read = SocialConnection::create(['company_id' => $this->company->id, 'meta_user_id' => '7001', 'access_token' => 'leitura',
            'granted_scopes' => SocialConnection::SCOPES, 'status' => SocialConnection::STATUS_ACTIVE, 'connected_at' => now()]);
        SocialConnectionAccount::create(['company_id' => $this->company->id, 'social_connection_id' => $read->id, 'platform' => 'facebook',
            'external_id' => '1001', 'page_id' => '1001', 'name' => 'Quebom', 'page_access_token' => 'pagina-leitura', 'is_primary' => true]);
        $this->fakeMeta([...SocialConnection::SCOPES, ...SocialPublishingGrant::SCOPES]);

        $location = $this->authorize("/api/v1/companies/{$this->company->id}/integrations/social/publish-auth-url");

        $this->assertStringEndsWith("/companies/{$this->company->id}?social=publish_ok", $location, 'antes dava sempre publish_scopes');
        $grant = SocialPublishingGrant::sole();
        $this->assertSame(['7001', 'token-longo'], [$grant->meta_user_id, $grant->access_token]);
        $this->assertEqualsCanonicalizing(SocialPublishingGrant::SCOPES, $grant->granted_scopes);
        $this->assertSame('pagina-publicar', SocialConnectionAccount::sole()->publish_access_token);
        $this->assertSame(['leitura', SocialConnection::STATUS_ACTIVE], [$read->fresh()->access_token, $read->fresh()->status], 'a leitura não muda');
    }

    public function test_agency_flow_connects_with_the_real_filter(): void
    {
        $this->company->forceFill(['agency_enabled_at' => now()])->save();
        $this->fakeMeta(AgencyMetaConnection::SCOPES);

        $location = $this->authorize("/api/v1/agencies/{$this->company->id}/meta/auth-url");

        $this->assertStringEndsWith('/agency?tab=meta&meta=ok', $location, 'antes dava sempre scopes');
        $c = AgencyMetaConnection::sole();
        $this->assertSame(['7001', AgencyMetaConnection::STATUS_ACTIVE], [$c->meta_user_id, $c->status]);
        $this->assertEqualsCanonicalizing(AgencyMetaConnection::SCOPES, $c->granted_scopes);
    }
}
