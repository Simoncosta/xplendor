<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\EditorialPost;
use App\Models\EditorialPostNetwork;
use App\Models\EditorialPostVersion;
use App\Models\MediaAsset;
use App\Models\SocialConnection;
use App\Models\SocialConnectionAccount;
use App\Models\SocialPublication;
use App\Services\Media\MediaProbe;
use App\Services\Social\Publishing\AutoPublisher;
use App\Services\Social\Publishing\FakeMetaPublishingClient;
use App\Services\Social\Publishing\MetaPublishingClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * F2.3 · Ficheiros para a Meta: ao programar, a PNG ganha um JPEG ("meta") e o vídeo é verificado
 * (H.264, AAC e a duração do formato) com o ffprobe simulado; se não servir, a publicação falha logo
 * com o motivo. O endereço para a Meta é assinado e absoluto, e no disco local a rota aceita-o.
 */
class MetaMediaFilesTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private FakeMetaPublishingClient $meta;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('media');
        config(['media.disk' => 'media']);
        $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00:00', 'Europe/Lisbon'));
        $this->meta = new FakeMetaPublishingClient();
        $this->app->instance(MetaPublishingClient::class, $this->meta);
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->a = Company::create(['nipc' => '500095881', 'fiscal_name' => 'Quebom Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $this->a->forceFill(['auto_publishing_enabled' => true])->save();
        CompanyModule::create(['company_id' => $this->a->id, 'module_key' => 'linha_editorial']);
        $connection = SocialConnection::create(['company_id' => $this->a->id, 'meta_user_id' => '1', 'access_token' => 't', 'status' => SocialConnection::STATUS_ACTIVE]);
        foreach ([['instagram', '17841'], ['facebook', '1001']] as [$platform, $id]) {
            SocialConnectionAccount::create(['company_id' => $this->a->id, 'social_connection_id' => $connection->id, 'platform' => $platform,
                'external_id' => $id, 'page_id' => '1001', 'name' => 'Quebom', 'page_access_token' => 'ptok', 'is_primary' => true]);
        }
    }

    private function png(): MediaAsset
    {
        $this->n++;
        $img = imagecreatetruecolor(2000, 1000);
        ob_start();
        imagepng($img);
        Storage::disk('media')->put("m/{$this->n}/original.png", (string) ob_get_clean());

        return MediaAsset::create(['company_id' => $this->a->id, 'kind' => 'image', 'disk' => 'media', 'dir' => "m/{$this->n}", 'original_name' => 'menu.png',
            'extension' => 'png', 'mime' => 'image/png', 'size_bytes' => 10, 'sha256' => hash('sha256', "m{$this->n}"), 'status' => MediaAsset::READY]);
    }

    private function video(array $extra = []): MediaAsset
    {
        $this->n++;
        Storage::disk('media')->put("m/{$this->n}/original.mp4", 'mp4');

        return MediaAsset::create(array_merge(['company_id' => $this->a->id, 'kind' => 'video', 'disk' => 'media', 'dir' => "m/{$this->n}", 'original_name' => 'reel.mp4',
            'extension' => 'mp4', 'mime' => 'video/mp4', 'size_bytes' => 3, 'sha256' => hash('sha256', "m{$this->n}"), 'status' => MediaAsset::READY,
            'codec' => 'h264', 'duration_ms' => 20000], $extra));
    }

    private function probe(string $video, string $audio, int $ms): void
    {
        $this->mock(MediaProbe::class, fn ($m) => $m->shouldReceive('probeStreams')->andReturn(['video_codec' => $video, 'audio_codec' => $audio, 'duration_ms' => $ms]));
    }

    /** Publicação Programada para 12/11 às 12:00 só no Instagram, com estes ficheiros. */
    private function scheduledPost(string $format, array $assets): EditorialPost
    {
        $post = EditorialPost::create(['company_id' => $this->a->id, 'publish_date' => '2026-11-12', 'publish_time' => '12:00', 'auto_publish' => true,
            'title' => 'Menu', 'format' => 'Imagem', 'channel' => 'instagram', 'media_format' => $format, 'stage' => EditorialPost::STAGE_PLANNING]);
        $version = EditorialPostVersion::create(['company_id' => $this->a->id, 'editorial_post_id' => $post->id, 'number' => 1, 'caption' => 'Venha',
            'status' => EditorialPostVersion::APPROVED]);
        foreach ($assets as $i => $asset) {
            DB::table('editorial_post_version_media')->insert(['version_id' => $version->id, 'media_asset_id' => $asset->id, 'position' => $i, 'role' => 'item']);
        }
        $post->forceFill(['current_version_id' => $version->id, 'approved_version_id' => $version->id])->save();
        $post->forceFill(['stage' => EditorialPost::STAGE_SCHEDULED])->save();

        return $post->fresh();
    }

    public function test_a_png_gets_a_jpeg_when_scheduled_and_the_meta_receives_a_signed_absolute_url(): void
    {
        $asset = $this->png();
        $this->scheduledPost('ig_feed_image', [$asset]);

        $asset->refresh();
        $this->assertSame('meta.jpg', $asset->variants['meta'] ?? null, 'o JPEG nasce ao programar');
        $jpeg = Storage::disk('media')->get("{$asset->dir}/meta.jpg");
        $this->assertSame([1440, 720, IMAGETYPE_JPEG], array_slice(getimagesizefromstring($jpeg), 0, 3));
        $this->assertSame('scheduled', SocialPublication::sole()->status);

        $this->travelTo(CarbonImmutable::parse('2026-11-12 12:00:30', 'Europe/Lisbon'));
        $this->assertSame(1, app(AutoPublisher::class)->runDue()['published']);
        $url = $this->meta->callsTo('createInstagramContainer')[0][1]['image_url'];
        $this->assertStringStartsWith(rtrim((string) config('app.url'), '/') . "/api/media/{$asset->id}/meta?", $url);
        $this->assertStringContainsString('signature=', $url);

        // A rota (signed:relative) aceita o endereço absoluto e serve o JPEG; adulterado, recusa.
        $res = $this->get($url);
        $res->assertOk();
        $this->assertSame('image/jpeg', $res->headers->get('Content-Type'));
        $this->get(str_replace('/meta?', '/original?', $url))->assertStatus(403);
        Http::assertNothingSent();
    }

    public function test_a_jpeg_goes_as_it_is(): void
    {
        $this->n++;
        Storage::disk('media')->put("m/{$this->n}/original.jpg", 'jpeg');
        $asset = MediaAsset::create(['company_id' => $this->a->id, 'kind' => 'image', 'disk' => 'media', 'dir' => "m/{$this->n}", 'original_name' => 'a.jpg',
            'extension' => 'jpg', 'mime' => 'image/jpeg', 'size_bytes' => 4, 'sha256' => 'x', 'status' => MediaAsset::READY]);
        $this->scheduledPost('ig_feed_image', [$asset]);

        $this->assertArrayNotHasKey('meta', (array) $asset->fresh()->variants);
        $this->assertStringContainsString("/api/media/{$asset->id}/original?", (string) $asset->externalUrl('original'));
    }

    public function test_a_video_that_does_not_fit_fails_when_scheduled_with_the_reason(): void
    {
        $this->probe('hevc', 'aac', 20000);
        $this->scheduledPost('ig_reel', [$this->video(['codec' => 'hevc'])]);
        $pub = SocialPublication::sole();
        $this->assertSame(['failed', 'media'], [$pub->status, $pub->error_code]);
        $this->assertStringContainsString('H.264', (string) $pub->error_message);

        $this->probe('h264', 'mp3', 20000);
        $this->scheduledPost('ig_reel', [$this->video()]);
        $this->assertStringContainsString('AAC', (string) SocialPublication::latest('id')->first()->error_message);

        $this->probe('h264', 'aac', 2000);
        $this->scheduledPost('ig_reel', [$this->video()]);
        $this->assertSame('Este formato aceita vídeos entre 3 e 15 minutos (este tem 2 segundos).', SocialPublication::latest('id')->first()->error_message);
        $this->assertSame([], $this->meta->calls, 'nada vai à Meta');
    }

    public function test_a_good_video_stays_scheduled_and_goes_out_with_its_signed_url(): void
    {
        $this->probe('h264', 'none', 20000);
        $video = $this->video();
        $this->scheduledPost('ig_reel', [$video]);

        $this->assertSame('scheduled', SocialPublication::sole()->status);
        $this->assertSame('none', $video->fresh()->audio_codec, 'sem som também serve');
        $this->travelTo(CarbonImmutable::parse('2026-11-12 12:00:30', 'Europe/Lisbon'));
        $this->assertSame(1, app(AutoPublisher::class)->runDue()['published']);
        $payload = $this->meta->callsTo('createInstagramContainer')[0][1];
        $this->assertSame('REELS', $payload['media_type']);
        $this->assertStringContainsString("/api/media/{$video->id}/original?", $payload['video_url']);
    }
}
