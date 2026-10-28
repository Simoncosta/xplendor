<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AgencyMetaConnection;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\SocialConnection;
use App\Models\SocialPublishingGrant;
use App\Services\Agency\AgencyMetaConnectionService;
use App\Services\Social\FakeMetaSocialClient;
use App\Services\Social\MetaSocialClient;
use App\Services\Social\MetaSocialClientInterface;
use App\Services\Social\SocialConnectionService;
use App\Services\Social\SocialPublishingConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Meta: desligar um fluxo revoga as permissões desse fluxo (também as de publicar e as da agência,
 * que o cliente recusava), mas nunca uma permissão que outra ligação ativa do mesmo utilizador da
 * Meta ainda use, em qualquer empresa ou fluxo. A Meta é sempre simulada.
 */
class MetaRevokeSharedScopesTest extends TestCase
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
        $this->plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 9, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function company(string $nipc): Company
    {
        return Company::create(['nipc' => $nipc, 'fiscal_name' => "Empresa {$nipc}", 'plan_id' => $this->plan, 'subscription_status' => 'active']);
    }

    private function social(Company $c, ?string $user): SocialConnection
    {
        return SocialConnection::create(['company_id' => $c->id, 'meta_user_id' => $user, 'access_token' => "leitura-{$c->id}", 'status' => SocialConnection::STATUS_ACTIVE,
            'granted_scopes' => SocialConnection::SCOPES]);
    }

    private function publishing(Company $c, ?string $user): SocialPublishingGrant
    {
        return SocialPublishingGrant::create(['company_id' => $c->id, 'meta_user_id' => $user, 'access_token' => "publicar-{$c->id}", 'granted_scopes' => SocialPublishingGrant::SCOPES,
            'connected_at' => now()]);
    }

    private function agency(Company $c, ?string $user): AgencyMetaConnection
    {
        return AgencyMetaConnection::create(['agency_company_id' => $c->id, 'meta_user_id' => $user, 'access_token' => "agencia-{$c->id}", 'granted_scopes' => AgencyMetaConnection::SCOPES]);
    }

    private function ads(Company $c, ?string $user): CompanyIntegration
    {
        return CompanyIntegration::create(['company_id' => $c->id, 'platform' => 'meta', 'meta_user_id' => $user, 'access_token' => "anuncios-{$c->id}", 'account_id' => '1', 'status' => 'active']);
    }

    /** As permissões que se pediram à Meta para revogar. */
    private function revoked(): array
    {
        $out = array_map(fn ($args) => $args[1], $this->meta->callsTo('revokePermission'));
        sort($out);

        return $out;
    }

    private function sorted(array $a): array
    {
        sort($a);

        return array_values($a);
    }

    // ── Revoga só o que ninguém usa ─────────────────────────────────────────

    public function test_disconnecting_publishing_revokes_its_permissions_when_nobody_else_uses_them(): void
    {
        $a = $this->company('500000951');
        $this->publishing($a, 'U1');
        $this->social($this->company('500000952'), 'U1'); // a leitura de outra empresa não usa as de publicar

        $out = app(SocialPublishingConnectionService::class)->disconnect($a->id);

        $this->assertSame($this->sorted(SocialPublishingGrant::SCOPES), $this->revoked());
        $this->assertFalse($out['permission_kept_shared']);
    }

    public function test_disconnecting_the_agency_revokes_business_management_ads_read_and_the_reading_ones_when_alone(): void
    {
        $agency = $this->company('500000953');
        $this->agency($agency, 'U2');

        $out = app(AgencyMetaConnectionService::class)->disconnect($agency->id);

        $this->assertSame($this->sorted(AgencyMetaConnection::SCOPES), $this->revoked());
        $this->assertSame([], $out['kept_shared']);
    }

    // ── Não revoga o que outra ligação usa ──────────────────────────────────

    public function test_the_agency_keeps_what_other_connections_of_the_same_meta_user_use(): void
    {
        $agency = $this->company('500000954');
        $this->agency($agency, 'U3');
        $this->social($this->company('500000955'), 'U3');   // usa as de leitura
        $this->ads($this->company('500000956'), 'U3');      // usa o ads_read
        $this->social($this->company('500000957'), 'U9');   // outro utilizador: não conta

        $out = app(AgencyMetaConnectionService::class)->disconnect($agency->id);

        $this->assertSame(['business_management'], $this->revoked());
        $this->assertSame($this->sorted([...SocialConnection::SCOPES, 'ads_read']), $this->sorted($out['kept_shared']));
    }

    public function test_publishing_elsewhere_keeps_the_publishing_and_the_reading_permissions(): void
    {
        $a = $this->company('500000958');
        $b = $this->company('500000959');
        $this->publishing($a, 'U4');
        $this->publishing($b, 'U4');

        $out = app(SocialPublishingConnectionService::class)->disconnect($a->id);
        $this->assertSame([], $this->revoked());
        $this->assertTrue($out['permission_kept_shared']);

        // A leitura de outra empresa, com a publicação do mesmo utilizador ainda ativa: as de leitura ficam.
        $reading = $this->social($a, 'U4');
        $out = app(SocialConnectionService::class)->disconnect($a->id, false);
        $this->assertSame([], $this->revoked());
        $this->assertTrue($out['permission_kept_shared']);
        $this->assertSame(SocialConnection::STATUS_REVOKED, $reading->fresh()->status); // desliga cá na mesma
    }

    public function test_an_unknown_meta_user_that_uses_the_permission_keeps_it(): void
    {
        $agency = $this->company('500000960');
        $this->agency($agency, 'U5');
        $this->ads($this->company('500000961'), null); // ligação antiga sem utilizador: pode ser o mesmo

        $out = app(AgencyMetaConnectionService::class)->disconnect($agency->id);

        $this->assertNotContains('ads_read', $this->revoked());
        $this->assertContains('business_management', $this->revoked());
        $this->assertContains('ads_read', $out['kept_shared']);
    }

    // ── O cliente da Meta ───────────────────────────────────────────────────

    public function test_the_real_client_revokes_any_permission_of_the_app_one_by_one_and_never_all(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);
        $client = new MetaSocialClient();

        foreach (['pages_manage_posts', 'instagram_content_publish', 'business_management', 'ads_read', 'pages_show_list'] as $permission) {
            $this->assertTrue($client->revokePermission('tok', $permission)['revoked'], $permission);
        }
        // Uma permissão que a app não pede, ou sem nome: nunca sai pedido nenhum.
        $this->assertFalse($client->revokePermission('tok', 'manage_everything')['revoked']);
        $this->assertFalse($client->revokePermission('tok', '')['revoked']);

        $paths = collect(Http::recorded())->map(fn ($p) => [$p[0]->method(), parse_url($p[0]->url(), PHP_URL_PATH)])->all();
        $this->assertCount(5, $paths);
        foreach ($paths as [$method, $path]) {
            $this->assertSame('DELETE', $method);
            $this->assertMatchesRegularExpression('#/me/permissions/[a-z_]+$#', $path);
        }
    }
}
