<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ContentReviewDigestJob;
use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\ContentReviewNotification;
use App\Models\EditorialPost;
use App\Models\EditorialPostVersion;
use App\Models\MediaAsset;
use App\Models\SocialConnection;
use App\Models\SocialConnectionAccount;
use App\Models\SocialPublication;
use App\Models\User;
use App\Services\Social\Publishing\AutoPublisher;
use App\Services\Social\Publishing\FakeMetaPublishingClient;
use App\Services\Social\Publishing\MetaPublishingClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * F2.7 · Fiabilidade da publicação automática: erros passageiros voltam a tentar aos 2, 10 e 30
 * minutos; erros definitivos e de token falham logo, com o motivo; a quota esgotada adia e avisa uma
 * vez; falhar de vez e ficar "a publicar" mais de 15 minutos avisam quem produz (sino e resumo, que
 * segue a guarda das chamadas externas).
 */
class PublishingReliabilityTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private FakeMetaPublishingClient $meta;

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
        SocialConnectionAccount::create(['company_id' => $this->a->id, 'social_connection_id' => $connection->id, 'platform' => 'instagram',
            'external_id' => '17841', 'page_id' => '1001', 'name' => 'Quebom', 'page_access_token' => 'ptok', 'is_primary' => true]);

        $post = EditorialPost::create(['company_id' => $this->a->id, 'publish_date' => '2026-11-12', 'publish_time' => '12:00', 'auto_publish' => true,
            'title' => 'Menu de Natal', 'format' => 'Imagem', 'channel' => 'instagram', 'media_format' => 'ig_feed_image', 'stage' => EditorialPost::STAGE_PLANNING]);
        $version = EditorialPostVersion::create(['company_id' => $this->a->id, 'editorial_post_id' => $post->id, 'number' => 1, 'caption' => 'Venha',
            'status' => EditorialPostVersion::APPROVED]);
        Storage::disk('media')->put('m/1/original.jpg', 'jpeg');
        $asset = MediaAsset::create(['company_id' => $this->a->id, 'kind' => 'image', 'disk' => 'media', 'dir' => 'm/1', 'original_name' => 'a.jpg',
            'extension' => 'jpg', 'mime' => 'image/jpeg', 'size_bytes' => 4, 'sha256' => 's1', 'status' => MediaAsset::READY]);
        DB::table('editorial_post_version_media')->insert(['version_id' => $version->id, 'media_asset_id' => $asset->id, 'position' => 0, 'role' => 'item']);
        $post->forceFill(['current_version_id' => $version->id, 'approved_version_id' => $version->id])->save();
        $post->forceFill(['stage' => EditorialPost::STAGE_SCHEDULED])->save();
        $this->travelTo(CarbonImmutable::parse('2026-11-12 12:00', 'Europe/Lisbon'));
    }

    private function alerts(): array
    {
        return ContentReviewNotification::where('company_id', $this->a->id)->orderBy('id')->pluck('title')->all();
    }

    public function test_a_passing_error_retries_at_2_10_and_30_minutes_then_fails_and_alerts_once(): void
    {
        $this->meta->failures['publishInstagram'] = FakeMetaPublishingClient::failure(MetaPublishingClient::KIND_TRANSIENT, 'A Meta está ocupada ou indisponível. Vai tentar de novo.', '2');
        $this->meta->failAlways = true;

        foreach ([[2, '12:02'], [10, '12:12'], [30, '12:42']] as [$minutes, $hour]) {
            $this->assertSame(1, app(AutoPublisher::class)->runDue()['waiting']);
            $pub = SocialPublication::sole();
            $this->assertSame('scheduled', $pub->status);
            $this->assertTrue($pub->next_attempt_at->equalTo(now()->addMinutes($minutes)));
            $this->assertStringEndsWith("Nova tentativa às {$hour}.", (string) $pub->error_message);
            $this->assertSame(0, app(AutoPublisher::class)->runDue()['waiting'], 'antes da hora marcada, nada');
            $this->travel($minutes)->minutes();
        }
        $this->assertSame([], $this->alerts(), 'as tentativas não avisam');

        $this->assertSame(1, app(AutoPublisher::class)->runDue()['failed']);
        $pub = SocialPublication::sole();
        $this->assertSame(['failed', 4, '2'], [$pub->status, $pub->attempts, $pub->error_code]);
        $this->assertCount(4, $this->meta->callsTo('publishInstagram'));
        $this->assertCount(1, $this->meta->callsTo('createInstagramContainer'), 'o mesmo contentor nas tentativas');
        $this->assertSame(['A publicação automática falhou: Menu de Natal'], $this->alerts());
        $this->assertNotNull($pub->alerted_at);
    }

    public function test_a_definitive_or_token_error_fails_at_once_with_the_reason(): void
    {
        $this->meta->failures['createInstagramContainer'] = FakeMetaPublishingClient::failure(MetaPublishingClient::KIND_EXPIRED,
            'A ligação às redes sociais expirou. Volte a ligar em Integrações › Redes sociais.', '190');

        $this->assertSame(1, app(AutoPublisher::class)->runDue()['failed']);
        $pub = SocialPublication::sole();
        $this->assertSame(['failed', 1, '190'], [$pub->status, $pub->attempts, $pub->error_code]);
        $this->assertSame(['A publicação automática falhou: Menu de Natal'], $this->alerts());
        $message = (string) ContentReviewNotification::sole()->message;
        $this->assertStringContainsString('Instagram: A ligação às redes sociais expirou.', $message);
        $this->assertStringContainsString('Pode publicar à mão', $message);
    }

    public function test_an_exhausted_quota_defers_alerts_once_and_fails_after_a_day(): void
    {
        $this->meta->quotaUsed = 100;
        $this->assertSame(1, app(AutoPublisher::class)->runDue()['waiting']);
        $pub = SocialPublication::sole();
        $this->assertSame(['scheduled', 0, 'quota'], [$pub->status, $pub->attempts, $pub->error_code], 'adiar não gasta tentativas');
        $this->assertTrue($pub->next_attempt_at->equalTo(now()->addHour()));
        $this->assertSame(['Quota do Instagram esgotada: Menu de Natal'], $this->alerts());

        $this->travel(61)->minutes();
        $this->assertSame(1, app(AutoPublisher::class)->runDue()['waiting']);
        $this->assertCount(1, $this->alerts(), 'avisa uma só vez');

        // Com quota outra vez, sai.
        $this->meta->quotaUsed = 10;
        $this->travel(61)->minutes();
        $this->assertSame(1, app(AutoPublisher::class)->runDue()['published']);
    }

    public function test_a_quota_deferred_for_more_than_a_day_fails(): void
    {
        $this->meta->quotaUsed = 100;
        app(AutoPublisher::class)->runDue();
        $this->travelTo(CarbonImmutable::parse('2026-11-13 12:30', 'Europe/Lisbon'));

        $this->assertSame(1, app(AutoPublisher::class)->runDue()['failed']);
        $pub = SocialPublication::sole();
        $this->assertSame('failed', $pub->status);
        $this->assertStringContainsString('Ficou adiada mais de 24 horas.', (string) $pub->error_message);
        $this->assertSame(['Quota do Instagram esgotada: Menu de Natal', 'A publicação automática falhou: Menu de Natal'], $this->alerts());
    }

    public function test_publishing_for_more_than_15_minutes_alerts_once(): void
    {
        SocialPublication::query()->update(['status' => 'publishing', 'updated_at' => now()->subMinutes(14)]);
        $this->assertSame(0, app(AutoPublisher::class)->alertStuck());
        SocialPublication::query()->update(['updated_at' => now()->subMinutes(16)]);
        $this->assertSame(1, app(AutoPublisher::class)->alertStuck());
        $this->assertSame(0, app(AutoPublisher::class)->alertStuck(), 'uma só vez');
        $this->assertSame(['Publicação automática presa: Menu de Natal'], $this->alerts());
        $this->assertSame('publishing', SocialPublication::sole()->status, 'não volta a tentar sozinha');
    }

    public function test_the_email_summary_follows_the_external_calls_guard(): void
    {
        Mail::fake();
        User::factory()->create(['company_id' => $this->a->id, 'role' => 'admin', 'email' => 'admin@quebom.test']);
        $this->meta->failures['createInstagramContainer'] = FakeMetaPublishingClient::failure(MetaPublishingClient::KIND_PERMANENT, 'Formato recusado.');
        app(AutoPublisher::class)->runDue();

        config(['scheduler.external_jobs' => false]);
        $this->assertSame(['emails' => 0, 'notifications' => 0], app(ContentReviewDigestJob::class)->handle());
        Mail::assertNothingSent();
        $this->assertNull(ContentReviewNotification::sole()->emailed_at, 'fica para quando a guarda abrir');
    }
}
