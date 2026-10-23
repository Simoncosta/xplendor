<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\EditorialPost;
use App\Models\EditorialPostVersion;
use App\Models\MediaAsset;
use App\Models\SocialConnection;
use App\Models\SocialConnectionAccount;
use App\Models\SocialPublication;
use App\Models\SocialPublishingGrant;
use App\Models\User;
use App\Services\Social\FakeMetaSocialClient;
use App\Services\Social\MetaSocialClientInterface;
use App\Services\Social\Publishing\AutoPublisher;
use App\Services\Social\SocialConnectionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * F2.8 · "Ligar publicação": uma autorização à parte (instagram_content_publish e
 * pages_manage_posts) que não mexe na ligação de leitura; os tokens das Páginas ficam nas contas
 * escolhidas e são os que o publicador usa. "Tentar de novo" numa publicação automática que
 * falhou. O interruptor "Publicação automática" nas Integrações. Outra empresa recebe 403 nas rotas novas.
 */
class PublishingConnectionTest extends TestCase
{
    use RefreshDatabase;

    private FakeMetaSocialClient $meta;

    private Company $a;

    private Company $b;

    private User $adminA;

    private User $adminB;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('media');
        config(['media.disk' => 'media']);
        $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00:00', 'Europe/Lisbon'));
        $this->meta = new FakeMetaSocialClient();
        $this->app->instance(MetaSocialClientInterface::class, $this->meta);
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->a = Company::create(['nipc' => '500095881', 'fiscal_name' => 'Quebom Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->b = Company::create(['nipc' => '500095882', 'fiscal_name' => 'Outra Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->adminA = User::factory()->create(['company_id' => $this->a->id, 'role' => 'admin']);
        $this->adminB = User::factory()->create(['company_id' => $this->b->id, 'role' => 'admin']);
    }

    private function as(User $u): self
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u, 'sanctum');
    }

    /** Leitura ligada com a Página 1001 e o Instagram 17841 escolhidos. */
    private function readConnection(Company $c, string $metaUser = 'U1'): SocialConnection
    {
        $connection = SocialConnection::create(['company_id' => $c->id, 'meta_user_id' => $metaUser, 'access_token' => 'leitura',
            'granted_scopes' => SocialConnection::SCOPES, 'status' => SocialConnection::STATUS_ACTIVE, 'connected_at' => now()]);
        SocialConnectionAccount::create(['company_id' => $c->id, 'social_connection_id' => $connection->id, 'platform' => 'facebook',
            'external_id' => '1001', 'page_id' => '1001', 'name' => 'Quebom', 'page_access_token' => 'pagina-leitura', 'is_primary' => true]);
        SocialConnectionAccount::create(['company_id' => $c->id, 'social_connection_id' => $connection->id, 'platform' => 'instagram',
            'external_id' => '17841', 'page_id' => '1001', 'name' => 'quebom', 'page_access_token' => 'pagina-leitura', 'is_primary' => true]);

        return $connection;
    }

    /** Começa "Ligar publicação" pela rota e volta pelo callback das redes. */
    private function connectPublishing(array $scopes): string
    {
        $url = (string) $this->as($this->adminA)->getJson("/api/v1/companies/{$this->a->id}/integrations/social/publish-auth-url")->assertOk()->json('data.url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('instagram_content_publish,pages_manage_posts', $q['scope']);
        $this->meta->token = ['token' => 'publicar', 'expires_at' => null, 'user_id' => 'U1', 'scopes' => [...SocialConnection::SCOPES, ...$scopes]];
        $this->meta->pages = [['id' => '1001', 'name' => 'Quebom', 'access_token' => 'pagina-publicar', 'instagram' => ['id' => '17841', 'username' => 'quebom', 'name' => 'Quebom']]];

        return (string) $this->get('/api/oauth/meta/social/callback?state=' . urlencode((string) $q['state']) . '&code=ok')->headers->get('Location');
    }

    public function test_connect_publishing_stores_page_tokens_without_touching_the_read_connection(): void
    {
        $this->as($this->adminA)->getJson("/api/v1/companies/{$this->a->id}/integrations/social/publish-auth-url")
            ->assertStatus(422)->assertJsonFragment(['Primeiro ligue as redes sociais e escolha as contas; depois ligue a publicação.']);

        $read = $this->readConnection($this->a);
        $location = $this->connectPublishing(SocialPublishingGrant::SCOPES);
        $this->assertStringEndsWith("/companies/{$this->a->id}?social=publish_ok", $location);

        $this->assertSame(['pagina-publicar', 'pagina-publicar'], SocialConnectionAccount::where('company_id', $this->a->id)->orderBy('id')->get()->pluck('publish_access_token')->all());
        $this->assertSame('pagina-leitura', SocialConnectionAccount::where('platform', 'facebook')->sole()->page_access_token, 'a leitura não muda');
        $read->refresh();
        $this->assertSame(['leitura', SocialConnection::STATUS_ACTIVE], [$read->access_token, $read->status]);
        $this->assertSame('publicar', SocialPublishingGrant::sole()->access_token);

        $state = $this->as($this->adminA)->getJson("/api/v1/companies/{$this->a->id}/integrations/social")->assertOk()->json('data.publishing');
        $this->assertTrue($state['connected']);
        $this->assertEqualsCanonicalizing(['facebook', 'instagram'], $state['platforms']);
        $this->assertStringNotContainsString('publicar"', (string) $this->getJson("/api/v1/companies/{$this->a->id}/integrations/social")->getContent(), 'nunca sai o token');
    }

    public function test_without_the_publish_permissions_nothing_is_stored_nor_revoked(): void
    {
        $this->readConnection($this->a);
        $location = $this->connectPublishing(['instagram_content_publish']);

        $this->assertStringEndsWith('social=error&reason=publish_scopes', $location);
        $this->assertSame(0, SocialPublishingGrant::count());
        $this->assertSame(0, SocialConnectionAccount::whereNotNull('publish_access_token')->count());
        $this->assertSame([], $this->meta->callsTo('revokePermission'), 'revogar podia cortar a leitura');
    }

    public function test_the_publisher_uses_the_publishing_token(): void
    {
        $this->readConnection($this->a);
        $this->connectPublishing(SocialPublishingGrant::SCOPES);
        $this->a->forceFill(['auto_publishing_enabled' => true])->save();
        CompanyModule::create(['company_id' => $this->a->id, 'module_key' => 'linha_editorial']);
        $post = EditorialPost::create(['company_id' => $this->a->id, 'publish_date' => '2026-11-12', 'publish_time' => '12:00', 'auto_publish' => true,
            'title' => 'Texto', 'format' => 'Texto', 'channel' => 'facebook', 'media_format' => 'fb_post', 'stage' => EditorialPost::STAGE_PLANNING]);
        $version = EditorialPostVersion::create(['company_id' => $this->a->id, 'editorial_post_id' => $post->id, 'number' => 1, 'caption' => 'Olá', 'status' => EditorialPostVersion::APPROVED]);
        $post->forceFill(['current_version_id' => $version->id, 'approved_version_id' => $version->id])->save();
        $post->forceFill(['stage' => EditorialPost::STAGE_SCHEDULED])->save();
        $this->travelTo(CarbonImmutable::parse('2026-11-12 12:00:30', 'Europe/Lisbon'));
        config(['social_publishing.driver' => 'meta']);
        Http::fake([
            'https://graph.facebook.com/v25.0/1001/feed' => Http::response(['id' => '1001_1']),
            'https://graph.facebook.com/v25.0/1001_1*' => Http::response(['permalink_url' => 'https://www.facebook.com/quebom/posts/1']),
        ]);

        $this->assertSame(1, app(AutoPublisher::class)->runDue()['published']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/1001/feed') && $r['access_token'] === 'pagina-publicar');
    }

    public function test_disconnecting_publishing_keeps_reading_and_revokes_only_the_publish_permissions(): void
    {
        $this->readConnection($this->a);
        $this->connectPublishing(SocialPublishingGrant::SCOPES);

        $this->as($this->adminA)->deleteJson("/api/v1/companies/{$this->a->id}/integrations/social/publishing")
            ->assertOk()->assertJsonPath('data.permission_kept_shared', false)->assertJsonPath('data.publishing.connected', false);
        $this->assertSame([['publicar', 'instagram_content_publish'], ['publicar', 'pages_manage_posts']], $this->meta->callsTo('revokePermission'));
        $this->assertSame(0, SocialConnectionAccount::whereNotNull('publish_access_token')->count());
        $this->assertSame(SocialConnection::STATUS_ACTIVE, SocialConnection::where('company_id', $this->a->id)->value('status'), 'a leitura continua');
        $this->assertSame(2, SocialConnectionAccount::where('company_id', $this->a->id)->count());

        // O mesmo utilizador da Meta publica noutra empresa: só desliga na XPLENDOR.
        $this->connectPublishing(SocialPublishingGrant::SCOPES);
        SocialPublishingGrant::create(['company_id' => $this->b->id, 'meta_user_id' => 'U1', 'access_token' => 'outra']);
        $this->as($this->adminA)->deleteJson("/api/v1/companies/{$this->a->id}/integrations/social/publishing")->assertOk()->assertJsonPath('data.permission_kept_shared', true);
        $this->assertCount(2, $this->meta->callsTo('revokePermission'), 'nada mais revogado');
    }

    public function test_retry_brings_a_failed_publication_back_and_only_a_failed_one(): void
    {
        $this->a->forceFill(['auto_publishing_enabled' => true])->save();
        CompanyModule::create(['company_id' => $this->a->id, 'module_key' => 'linha_editorial']);
        $post = EditorialPost::create(['company_id' => $this->a->id, 'publish_date' => '2026-11-12', 'publish_time' => '12:00', 'auto_publish' => true,
            'title' => 'Texto', 'format' => 'Texto', 'channel' => 'instagram', 'media_format' => 'ig_feed_image', 'stage' => EditorialPost::STAGE_PLANNING]);
        $version = EditorialPostVersion::create(['company_id' => $this->a->id, 'editorial_post_id' => $post->id, 'number' => 1, 'caption' => 'Olá', 'status' => EditorialPostVersion::APPROVED]);
        Storage::disk('media')->put('m/1/original.jpg', 'jpeg');
        $asset = MediaAsset::create(['company_id' => $this->a->id, 'kind' => 'image', 'disk' => 'media', 'dir' => 'm/1', 'original_name' => 'a.jpg',
            'extension' => 'jpg', 'mime' => 'image/jpeg', 'size_bytes' => 4, 'sha256' => 's1', 'status' => MediaAsset::READY]);
        DB::table('editorial_post_version_media')->insert(['version_id' => $version->id, 'media_asset_id' => $asset->id, 'position' => 0, 'role' => 'item']);
        $post->forceFill(['current_version_id' => $version->id, 'approved_version_id' => $version->id])->save();
        $post->forceFill(['stage' => EditorialPost::STAGE_SCHEDULED])->save();
        $url = "/api/v1/companies/{$this->a->id}/editorial/posts/{$post->id}/retry-publication";

        $this->as($this->adminA)->postJson($url, ['network' => 'instagram'])->assertStatus(422);
        $pub = SocialPublication::sole();
        $this->travelTo(CarbonImmutable::parse('2026-11-12 13:00', 'Europe/Lisbon'));
        $pub->forceFill(['status' => 'failed', 'attempts' => 4, 'container_id' => 'c-1', 'error_code' => '2', 'error_message' => 'A Meta está ocupada.', 'alerted_at' => now()])->save();

        $res = $this->as($this->adminA)->postJson($url, ['network' => 'instagram'])->assertOk();
        $pub->refresh();
        $this->assertSame(['scheduled', 0, null, null, 'c-1'], [$pub->status, $pub->attempts, $pub->error_message, $pub->alerted_at, $pub->container_id]);
        $this->assertTrue($pub->next_attempt_at->equalTo(now()), 'a hora já passou: sai no minuto seguinte');
        $this->assertSame('scheduled', collect($res->json('data.post.networks'))->firstWhere('network', 'instagram')['publication']['status']);
    }

    public function test_another_company_gets_403_on_the_new_routes(): void
    {
        $this->readConnection($this->a);
        $this->as($this->adminB)->getJson("/api/v1/companies/{$this->a->id}/integrations/social/publish-auth-url")->assertStatus(403);
        $this->as($this->adminB)->deleteJson("/api/v1/companies/{$this->a->id}/integrations/social/publishing")->assertStatus(403);
        $this->as($this->adminB)->postJson("/api/v1/companies/{$this->a->id}/editorial/posts/1/retry-publication", ['network' => 'instagram'])->assertStatus(403);
        $this->as($this->adminB)->putJson("/api/v1/companies/{$this->a->id}/integrations/social/auto-publishing", ['enabled' => true])->assertStatus(403);
        $this->assertFalse((bool) $this->a->fresh()->auto_publishing_enabled);
        $this->assertSame([], $this->meta->callsTo('authUrl'));
    }

    public function test_the_switch_lives_in_integrations_and_schedules_the_programmed_posts(): void
    {
        CompanyModule::create(['company_id' => $this->a->id, 'module_key' => 'linha_editorial']);
        $post = EditorialPost::create(['company_id' => $this->a->id, 'publish_date' => '2026-11-12', 'publish_time' => '12:00', 'auto_publish' => true,
            'title' => 'Texto', 'format' => 'Texto', 'channel' => 'facebook', 'media_format' => 'fb_post', 'stage' => EditorialPost::STAGE_SCHEDULED]);
        $this->assertSame(0, SocialPublication::count(), 'com o interruptor desligado, continua manual');

        // Um utilizador sem integracoes.configurar não liga o interruptor.
        $user = User::factory()->create(['company_id' => $this->a->id, 'role' => 'user']);
        $this->as($user)->putJson("/api/v1/companies/{$this->a->id}/integrations/social/auto-publishing", ['enabled' => true])->assertStatus(403);

        $this->as($this->adminA)->putJson("/api/v1/companies/{$this->a->id}/integrations/social/auto-publishing", ['enabled' => true])
            ->assertOk()->assertJsonPath('data.publishing.auto_publishing_enabled', true);
        $this->assertSame(SocialPublication::STATUS_SCHEDULED, SocialPublication::where('editorial_post_id', $post->id)->sole()->status);

        $this->as($this->adminA)->putJson("/api/v1/companies/{$this->a->id}/integrations/social/auto-publishing", ['enabled' => false])->assertOk();
        $this->assertSame(SocialPublication::STATUS_CANCELLED, SocialPublication::sole()->status);
    }
}
