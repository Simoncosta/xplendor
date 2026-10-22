<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\PublishDueSocialPublicationsJob;
use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\EditorialPost;
use App\Models\EditorialPostVersion;
use App\Models\MediaAsset;
use App\Models\SocialConnection;
use App\Models\SocialConnectionAccount;
use App\Models\SocialPublication;
use App\Services\Social\Publishing\AutoPublisher;
use App\Services\Social\Publishing\FakeMetaPublishingClient;
use App\Services\Social\Publishing\MetaGraphPublishingClient;
use App\Services\Social\Publishing\MetaPublishingClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * F2.4 · Instagram: imagem e carrossel com o cliente real da Graph API, sempre com respostas
 * simuladas (Http::fake): quota, contentor, estado, media_publish e o link automático. Os erros
 * da Meta ficam classificados (passageiro, definitivo, token) com o motivo em linguagem simples, e
 * um contentor que já saiu nunca é publicado outra vez.
 */
class InstagramGraphPublishingTest extends TestCase
{
    use RefreshDatabase;

    private const G = 'https://graph.facebook.com/v25.0';

    private Company $a;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('media');
        config(['media.disk' => 'media', 'social_publishing.driver' => 'meta']);
        $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00:00', 'Europe/Lisbon'));
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->a = Company::create(['nipc' => '500095881', 'fiscal_name' => 'Quebom Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->a->forceFill(['auto_publishing_enabled' => true])->save();
        CompanyModule::create(['company_id' => $this->a->id, 'module_key' => 'linha_editorial']);
        $connection = SocialConnection::create(['company_id' => $this->a->id, 'meta_user_id' => '1', 'access_token' => 't', 'status' => SocialConnection::STATUS_ACTIVE]);
        SocialConnectionAccount::create(['company_id' => $this->a->id, 'social_connection_id' => $connection->id, 'platform' => 'instagram',
            'external_id' => '17841', 'page_id' => '1001', 'name' => 'Quebom', 'page_access_token' => 'ptok', 'is_primary' => true]);
    }

    private function jpeg(int $n): MediaAsset
    {
        Storage::disk('media')->put("m/{$n}/original.jpg", 'jpeg');

        return MediaAsset::create(['company_id' => $this->a->id, 'kind' => 'image', 'disk' => 'media', 'dir' => "m/{$n}", 'original_name' => "{$n}.jpg",
            'extension' => 'jpg', 'mime' => 'image/jpeg', 'size_bytes' => 4, 'sha256' => "s{$n}", 'status' => MediaAsset::READY]);
    }

    private function scheduledPost(string $format, int $files): EditorialPost
    {
        $post = EditorialPost::create(['company_id' => $this->a->id, 'publish_date' => '2026-11-12', 'publish_time' => '12:00', 'auto_publish' => true,
            'title' => 'Menu', 'format' => 'Imagem', 'channel' => 'instagram', 'media_format' => $format, 'stage' => EditorialPost::STAGE_PLANNING]);
        $version = EditorialPostVersion::create(['company_id' => $this->a->id, 'editorial_post_id' => $post->id, 'number' => 1, 'caption' => 'Venha provar',
            'hashtags' => ['natal'], 'status' => EditorialPostVersion::APPROVED]);
        for ($i = 0; $i < $files; $i++) {
            DB::table('editorial_post_version_media')->insert(['version_id' => $version->id, 'media_asset_id' => $this->jpeg($i + 1)->id, 'position' => $i, 'role' => 'item']);
        }
        $post->forceFill(['current_version_id' => $version->id, 'approved_version_id' => $version->id])->save();
        $post->forceFill(['stage' => EditorialPost::STAGE_SCHEDULED])->save();
        $this->travelTo(CarbonImmutable::parse('2026-11-12 12:00:30', 'Europe/Lisbon'));

        return $post->fresh();
    }

    private function fakeGraph(array $containers, array $override = []): void
    {
        Http::fake(array_merge([
            self::G . '/17841/content_publishing_limit*' => Http::response(['data' => [['quota_usage' => 3, 'config' => ['quota_total' => 100]]]]),
            self::G . '/17841/media_publish' => Http::response(['id' => 'ig-900']),
            self::G . '/17841/media' => Http::sequence(array_map(fn ($id) => Http::response(['id' => $id]), $containers)),
            self::G . '/ig-900*' => Http::response(['permalink' => 'https://www.instagram.com/p/ABC/', 'timestamp' => '2026-11-12T12:00:40+0000']),
            self::G . '/*' => Http::response(['status_code' => 'FINISHED']),
        ], $override));
    }

    public function test_the_driver_picks_the_real_client_only_with_meta(): void
    {
        $this->assertInstanceOf(MetaGraphPublishingClient::class, app(MetaPublishingClient::class));
        config(['social_publishing.driver' => 'fake']);
        $this->assertInstanceOf(FakeMetaPublishingClient::class, app(MetaPublishingClient::class));

        // Com o cliente real, o publicador só corre com a guarda das chamadas externas ligada.
        config(['social_publishing.driver' => 'meta', 'scheduler.external_jobs' => false]);
        $this->assertSame(['skipped' => true], app(PublishDueSocialPublicationsJob::class)->handle(app(AutoPublisher::class)));
        Http::assertNothingSent();
    }

    public function test_an_image_goes_out_through_the_graph_api_with_the_automatic_link(): void
    {
        $post = $this->scheduledPost('ig_feed_image', 1);
        $this->fakeGraph(['c-1']);

        $this->assertSame(1, app(AutoPublisher::class)->runDue()['published']);

        $paths = collect(Http::recorded())->map(fn ($r) => $r[0]->method() . ' ' . strtok(str_replace(self::G, '', $r[0]->url()), '?'))->all();
        $this->assertSame(['GET /17841/content_publishing_limit', 'POST /17841/media', 'GET /c-1', 'POST /17841/media_publish', 'GET /ig-900'], $paths);
        Http::assertSent(fn (Request $r) => $r->url() === self::G . '/17841/media' && ! isset($r['media_type'])
            && $r['caption'] === "Venha provar\n\n#natal" && str_contains((string) $r['image_url'], '/api/media/') && $r['access_token'] === 'ptok');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/media_publish') && $r['creation_id'] === 'c-1');

        $pub = SocialPublication::sole();
        $this->assertSame(['published', 'c-1', 'ig-900', 'https://www.instagram.com/p/ABC/'], [$pub->status, $pub->container_id, $pub->media_id, $pub->permalink]);
        $ig = $post->networks()->where('network', 'instagram')->sole();
        $this->assertSame('https://www.instagram.com/p/ABC/', $ig->published_url);
        $this->assertSame(EditorialPost::STAGE_PUBLISHED, $post->fresh()->stage);
    }

    public function test_a_carousel_creates_one_item_per_file_and_then_the_carousel(): void
    {
        $this->scheduledPost('ig_carousel', 3);
        $this->fakeGraph(['c-1', 'c-2', 'c-3', 'c-car']);

        $this->assertSame(1, app(AutoPublisher::class)->runDue()['published']);
        $creates = collect(Http::recorded())->map(fn ($r) => $r[0])->filter(fn (Request $r) => $r->url() === self::G . '/17841/media')->values();
        $this->assertCount(4, $creates);
        foreach ([0, 1, 2] as $i) {
            $this->assertSame('true', $creates[$i]['is_carousel_item']);
            $this->assertFalse(isset($creates[$i]['caption']), 'a legenda vai só no carrossel');
        }
        $this->assertSame(['CAROUSEL', 'c-1,c-2,c-3'], [$creates[3]['media_type'], $creates[3]['children']]);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/media_publish') && $r['creation_id'] === 'c-car');
    }

    public function test_meta_errors_are_classified_with_a_plain_reason(): void
    {
        $classify = fn (int $status, array $error) => MetaGraphPublishingClient::classify($status, $error);
        $this->assertSame('expired', $classify(400, ['code' => 190])['kind']);
        $this->assertSame('transient', $classify(429, [])['kind']);
        $this->assertSame('transient', $classify(400, ['code' => 4])['kind']);
        $this->assertSame('transient', $classify(400, ['code' => 1, 'is_transient' => true])['kind']);
        $perm = $classify(403, ['code' => 10]);
        $this->assertSame(['permanent', '10'], [$perm['kind'], $perm['code']]);
        $this->assertStringContainsString('Ligue a publicação', $perm['message']);
        $this->assertSame('A proporção da imagem não é aceite pelo Instagram (entre 4:5 e 1,91:1).', $classify(400, ['code' => 100, 'error_subcode' => 36003])['message']);

        // Pelo publicador: o motivo fica na publicação.
        $this->scheduledPost('ig_feed_image', 1);
        $this->fakeGraph(['c-1'], [self::G . '/17841/media' => Http::response(['error' => ['code' => 100, 'error_subcode' => 36003]], 400)]);
        app(AutoPublisher::class)->runDue();
        $pub = SocialPublication::sole();
        $this->assertSame(['failed', '100.36003'], [$pub->status, $pub->error_code]);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/media_publish'));
    }

    public function test_a_container_that_already_went_out_is_never_published_again(): void
    {
        $fake = new FakeMetaPublishingClient();
        $this->app->instance(MetaPublishingClient::class, $fake);
        $this->scheduledPost('ig_feed_image', 1);
        // Uma tentativa anterior criou o contentor e caiu depois do media_publish.
        SocialPublication::query()->update(['container_id' => 'c-old']);
        $fake->containerState = 'PUBLISHED';

        $this->assertSame(1, app(AutoPublisher::class)->runDue()['published']);
        $this->assertSame([], $fake->callsTo('publishInstagram'), 'nunca duas vezes');
        $this->assertSame([], $fake->callsTo('createInstagramContainer'));
        $this->assertSame([['c-old']], $fake->callsTo('containerStatus'));
        $this->assertSame('published', SocialPublication::sole()->status);
    }

    public function test_a_container_still_ready_is_published_without_creating_another(): void
    {
        $fake = new FakeMetaPublishingClient();
        $this->app->instance(MetaPublishingClient::class, $fake);
        $this->scheduledPost('ig_feed_image', 1);
        SocialPublication::query()->update(['container_id' => 'c-ready']);

        $this->assertSame(1, app(AutoPublisher::class)->runDue()['published']);
        $this->assertSame([['17841', 'c-ready']], $fake->callsTo('publishInstagram'));
        $this->assertSame([], $fake->callsTo('createInstagramContainer'));
    }
}
