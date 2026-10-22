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
use App\Services\Social\Publishing\FakeMetaPublishingClient;
use App\Services\Social\Publishing\MetaPublishingClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * F2.5 · Instagram: Reel com capa. O contentor leva REELS, o vídeo, a legenda também no feed e a
 * capa (ou o instante da miniatura). Enquanto o Instagram processa o vídeo, o worker não fica preso:
 * a publicação volta a "agendada" com a próxima verificação daqui a um minuto, sem gastar
 * tentativas, até FINISHED (publica), ERROR ou EXPIRED (falha) ou 30 minutos (falha).
 */
class InstagramReelPublishingTest extends TestCase
{
    use RefreshDatabase;

    private const G = 'https://graph.facebook.com/v25.0';

    private Company $a;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('media');
        config(['media.disk' => 'media']);
        $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00:00', 'Europe/Lisbon'));
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->a = Company::create(['nipc' => '500095881', 'fiscal_name' => 'Quebom Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->a->forceFill(['auto_publishing_enabled' => true])->save();
        CompanyModule::create(['company_id' => $this->a->id, 'module_key' => 'linha_editorial']);
        $connection = SocialConnection::create(['company_id' => $this->a->id, 'meta_user_id' => '1', 'access_token' => 't', 'status' => SocialConnection::STATUS_ACTIVE]);
        SocialConnectionAccount::create(['company_id' => $this->a->id, 'social_connection_id' => $connection->id, 'platform' => 'instagram',
            'external_id' => '17841', 'page_id' => '1001', 'name' => 'Quebom', 'page_access_token' => 'ptok', 'is_primary' => true]);
    }

    private function fake(): FakeMetaPublishingClient
    {
        $fake = new FakeMetaPublishingClient();
        $this->app->instance(MetaPublishingClient::class, $fake);

        return $fake;
    }

    /** Reel Programado para 12/11 às 12:00 (vídeo H.264/AAC de 20 s), com ou sem capa em PNG; já à hora. */
    private function reel(bool $withCover): MediaAsset
    {
        $post = EditorialPost::create(['company_id' => $this->a->id, 'publish_date' => '2026-11-12', 'publish_time' => '12:00', 'auto_publish' => true,
            'title' => 'Reel', 'format' => 'Reel', 'channel' => 'instagram', 'media_format' => 'ig_reel', 'stage' => EditorialPost::STAGE_PLANNING]);
        $version = EditorialPostVersion::create(['company_id' => $this->a->id, 'editorial_post_id' => $post->id, 'number' => 1, 'caption' => 'Bastidores',
            'status' => EditorialPostVersion::APPROVED]);
        Storage::disk('media')->put('m/1/original.mp4', 'mp4');
        $video = MediaAsset::create(['company_id' => $this->a->id, 'kind' => 'video', 'disk' => 'media', 'dir' => 'm/1', 'original_name' => 'r.mp4', 'extension' => 'mp4',
            'mime' => 'video/mp4', 'size_bytes' => 3, 'sha256' => 's1', 'status' => MediaAsset::READY, 'codec' => 'h264', 'audio_codec' => 'aac', 'duration_ms' => 20000]);
        DB::table('editorial_post_version_media')->insert(['version_id' => $version->id, 'media_asset_id' => $video->id, 'position' => 0, 'role' => 'item']);
        if ($withCover) {
            ob_start();
            imagepng(imagecreatetruecolor(1080, 1920));
            Storage::disk('media')->put('m/2/original.png', (string) ob_get_clean());
            $cover = MediaAsset::create(['company_id' => $this->a->id, 'kind' => 'image', 'disk' => 'media', 'dir' => 'm/2', 'original_name' => 'c.png', 'extension' => 'png',
                'mime' => 'image/png', 'size_bytes' => 3, 'sha256' => 's2', 'status' => MediaAsset::READY]);
            DB::table('editorial_post_version_media')->insert(['version_id' => $version->id, 'media_asset_id' => $cover->id, 'position' => 1, 'role' => 'cover']);
        }
        $post->forceFill(['current_version_id' => $version->id, 'approved_version_id' => $version->id])->save();
        $post->forceFill(['stage' => EditorialPost::STAGE_SCHEDULED])->save();
        $this->travelTo(CarbonImmutable::parse('2026-11-12 12:00:30', 'Europe/Lisbon'));

        return $video;
    }

    public function test_the_reel_container_carries_the_video_the_cover_and_share_to_feed(): void
    {
        $this->reel(true);
        Http::fake([
            self::G . '/17841/content_publishing_limit*' => Http::response(['data' => [['quota_usage' => 0, 'config' => ['quota_total' => 100]]]]),
            self::G . '/17841/media_publish' => Http::response(['id' => 'ig-7']),
            self::G . '/17841/media' => Http::response(['id' => 'c-reel']),
            self::G . '/ig-7*' => Http::response(['permalink' => 'https://www.instagram.com/reel/XYZ/']),
            self::G . '/c-reel*' => Http::response(['status_code' => 'FINISHED']),
        ]);
        config(['social_publishing.driver' => 'meta']);

        $this->assertSame(1, app(AutoPublisher::class)->runDue()['published']);
        Http::assertSent(fn (Request $r) => $r->url() === self::G . '/17841/media' && $r['media_type'] === 'REELS' && $r['share_to_feed'] === 'true'
            && str_contains((string) $r['video_url'], '/original?') && str_contains((string) $r['cover_url'], '/meta?') && ! isset($r['thumb_offset']));
        $this->assertSame('https://www.instagram.com/reel/XYZ/', SocialPublication::sole()->permalink);
    }

    public function test_without_a_cover_it_uses_the_thumbnail_instant(): void
    {
        $fake = $this->fake();
        $this->reel(false);
        app(AutoPublisher::class)->runDue();
        $payload = $fake->callsTo('createInstagramContainer')[0][1];
        $this->assertSame([1000, true], [$payload['thumb_offset'], $payload['share_to_feed']]);
        $this->assertArrayNotHasKey('cover_url', $payload);
    }

    public function test_while_the_video_is_processing_it_checks_again_later_without_holding_the_worker(): void
    {
        $fake = $this->fake();
        $this->reel(true);
        $fake->containerState = 'IN_PROGRESS';

        $this->assertSame(['published' => 0, 'failed' => 0, 'cancelled' => 0, 'waiting' => 1], app(AutoPublisher::class)->runDue());
        $pub = SocialPublication::sole();
        $this->assertSame(['scheduled', 0], [$pub->status, $pub->attempts], 'esperar não gasta tentativas');
        $this->assertTrue($pub->next_attempt_at->equalTo(now()->addMinute()));
        $this->assertSame([], $fake->callsTo('publishInstagram'));
        $this->assertSame(0, app(AutoPublisher::class)->runDue()['waiting'], 'antes do minuto, nada');

        // Um minuto depois: o contentor já está pronto; publica esse, sem criar outro.
        $fake->containerState = 'FINISHED';
        $this->travel(61)->seconds();
        $this->assertSame(1, app(AutoPublisher::class)->runDue()['published']);
        $this->assertCount(1, $fake->callsTo('createInstagramContainer'));
        $this->assertSame([['17841', $pub->fresh()->container_id]], $fake->callsTo('publishInstagram'));
    }

    public function test_an_error_fails_and_waiting_too_long_fails(): void
    {
        $fake = $this->fake();
        $this->reel(false);
        $fake->containerState = 'ERROR';
        app(AutoPublisher::class)->runDue();
        $pub = SocialPublication::sole();
        $this->assertSame(['failed', 'permanent'], [$pub->status, $pub->error_code]);

        // A processar há mais de 30 minutos depois da hora: falha, com o motivo.
        $pub->forceFill(['status' => 'scheduled', 'error_code' => null, 'error_message' => null, 'next_attempt_at' => null])->save();
        $fake->containerState = 'IN_PROGRESS';
        $this->travelTo(CarbonImmutable::parse('2026-11-12 12:31', 'Europe/Lisbon'));
        app(AutoPublisher::class)->runDue();
        $this->assertSame(['failed', 'O Instagram não acabou de processar o vídeo em 30 minutos.'], [$pub->fresh()->status, $pub->fresh()->error_message]);
    }
}
