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
use App\Services\Social\Publishing\AutoPublisher;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * F2.6 · Facebook com o cliente real da Graph API e respostas simuladas (Http::fake): uma
 * fotografia (POST /photos), várias (fotografias por publicar e depois POST /feed com
 * attached_media), vídeo (POST /videos) e texto (POST /feed); no fim, o permalink_url. Os Reels
 * do Facebook continuam manuais (F5).
 */
class FacebookGraphPublishingTest extends TestCase
{
    use RefreshDatabase;

    private const G = 'https://graph.facebook.com/v25.0';

    private Company $a;

    private int $n = 0;

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
        SocialConnectionAccount::create(['company_id' => $this->a->id, 'social_connection_id' => $connection->id, 'platform' => 'facebook',
            'external_id' => '1001', 'page_id' => '1001', 'name' => 'Quebom', 'page_access_token' => 'ptok', 'is_primary' => true]);
    }

    private function asset(string $kind): MediaAsset
    {
        $this->n++;
        $ext = $kind === 'video' ? 'mp4' : 'jpg';
        Storage::disk('media')->put("m/{$this->n}/original.{$ext}", 'x');

        return MediaAsset::create(['company_id' => $this->a->id, 'kind' => $kind, 'disk' => 'media', 'dir' => "m/{$this->n}", 'original_name' => "f.{$ext}",
            'extension' => $ext, 'mime' => $kind === 'video' ? 'video/mp4' : 'image/jpeg', 'size_bytes' => 1, 'sha256' => "s{$this->n}", 'status' => MediaAsset::READY,
            'codec' => $kind === 'video' ? 'h264' : null, 'audio_codec' => $kind === 'video' ? 'aac' : null, 'duration_ms' => $kind === 'video' ? 45000 : null]);
    }

    /** Publicação do Facebook Programada para 12/11 às 12:00, já à hora. */
    private function scheduled(string $format, array $assets): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00:00', 'Europe/Lisbon'));
        $post = EditorialPost::create(['company_id' => $this->a->id, 'publish_date' => '2026-11-12', 'publish_time' => '12:00', 'auto_publish' => true,
            'title' => 'Menu', 'format' => 'Fotografias', 'channel' => 'facebook', 'media_format' => $format, 'stage' => EditorialPost::STAGE_PLANNING]);
        $version = EditorialPostVersion::create(['company_id' => $this->a->id, 'editorial_post_id' => $post->id, 'number' => 1, 'caption' => 'Venha provar',
            'status' => EditorialPostVersion::APPROVED]);
        foreach ($assets as $i => $asset) {
            DB::table('editorial_post_version_media')->insert(['version_id' => $version->id, 'media_asset_id' => $asset->id, 'position' => $i, 'role' => 'item']);
        }
        $post->forceFill(['current_version_id' => $version->id, 'approved_version_id' => $version->id])->save();
        $post->forceFill(['stage' => EditorialPost::STAGE_SCHEDULED])->save();
        $this->travelTo(CarbonImmutable::parse('2026-11-12 12:00:30', 'Europe/Lisbon'));
    }

    private function sent(): array
    {
        return collect(Http::recorded())->map(fn ($r) => $r[0]->method() . ' ' . strtok(str_replace(self::G, '', $r[0]->url()), '?'))->all();
    }

    public function test_one_photo_goes_to_photos_with_the_message(): void
    {
        $this->scheduled('fb_photos', [$this->asset('image')]);
        Http::fake([
            self::G . '/1001/photos' => Http::response(['id' => 'ph-1', 'post_id' => '1001_55']),
            self::G . '/1001_55*' => Http::response(['permalink_url' => 'https://www.facebook.com/quebom/posts/55']),
        ]);

        $this->assertSame(1, app(AutoPublisher::class)->runDue()['published']);
        $this->assertSame(['POST /1001/photos', 'GET /1001_55'], $this->sent());
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/1001/photos') && $r['message'] === 'Venha provar'
            && str_contains((string) $r['url'], '/api/media/') && $r['access_token'] === 'ptok' && ! isset($r['published']));
        $pub = SocialPublication::sole();
        $this->assertSame(['published', '1001_55', 'https://www.facebook.com/quebom/posts/55'], [$pub->status, $pub->media_id, $pub->permalink]);
    }

    public function test_several_photos_are_uploaded_unpublished_and_attached_to_one_post(): void
    {
        $this->scheduled('fb_photos', [$this->asset('image'), $this->asset('image'), $this->asset('image')]);
        Http::fake([
            self::G . '/1001/photos' => Http::sequence()->push(['id' => 'p1'])->push(['id' => 'p2'])->push(['id' => 'p3']),
            self::G . '/1001/feed' => Http::response(['id' => '1001_77']),
            self::G . '/1001_77*' => Http::response(['permalink_url' => 'https://www.facebook.com/quebom/posts/77']),
        ]);

        $this->assertSame(1, app(AutoPublisher::class)->runDue()['published']);
        $this->assertSame(['POST /1001/photos', 'POST /1001/photos', 'POST /1001/photos', 'POST /1001/feed', 'GET /1001_77'], $this->sent());
        $photos = collect(Http::recorded())->map(fn ($r) => $r[0])->filter(fn (Request $r) => str_ends_with($r->url(), '/photos'))->values();
        $this->assertSame(['false', 'false', 'false'], $photos->map(fn (Request $r) => $r['published'])->all(), 'por publicar até ao post');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/1001/feed') && $r['message'] === 'Venha provar'
            && $r['attached_media[0]'] === '{"media_fbid":"p1"}' && $r['attached_media[2]'] === '{"media_fbid":"p3"}');
        $this->assertSame('https://www.facebook.com/quebom/posts/77', SocialPublication::sole()->permalink);
    }

    public function test_a_video_goes_to_videos_and_a_relative_permalink_becomes_absolute(): void
    {
        $this->scheduled('fb_video', [$this->asset('video')]);
        Http::fake([
            self::G . '/1001/videos' => Http::response(['id' => 'v-9']),
            self::G . '/v-9*' => Http::response(['permalink_url' => '/quebom/videos/9/']),
        ]);

        $this->assertSame(1, app(AutoPublisher::class)->runDue()['published']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/1001/videos') && $r['description'] === 'Venha provar'
            && str_contains((string) $r['file_url'], '/original?'));
        $this->assertSame('https://www.facebook.com/quebom/videos/9/', SocialPublication::sole()->permalink);
    }

    public function test_text_goes_to_feed_and_reels_stay_manual(): void
    {
        $this->scheduled('fb_post', []);
        Http::fake([
            self::G . '/1001/feed' => Http::response(['id' => '1001_3']),
            self::G . '/1001_3*' => Http::response(['permalink_url' => 'https://www.facebook.com/quebom/posts/3']),
        ]);
        $this->assertSame(1, app(AutoPublisher::class)->runDue()['published']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/1001/feed') && $r['message'] === 'Venha provar' && ! isset($r['attached_media[0]']));

        SocialPublication::query()->delete();
        $this->scheduled('fb_reel', [$this->asset('video')]);
        $this->assertSame(1, app(AutoPublisher::class)->runDue()['failed']);
        $this->assertSame('Os Reels do Facebook ainda não saem sozinhos: publique à mão e marque como publicada.', SocialPublication::sole()->error_message);
        $this->assertCount(2, Http::recorded(), 'o Reel não chama a Meta');
    }
}
