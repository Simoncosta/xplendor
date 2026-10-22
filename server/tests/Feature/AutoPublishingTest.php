<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\PublishDueSocialPublicationsJob;
use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\EditorialPost;
use App\Models\EditorialPostNetwork;
use App\Models\EditorialPostVersion;
use App\Models\MediaAsset;
use App\Models\SocialConnection;
use App\Models\SocialConnectionAccount;
use App\Models\SocialPublication;
use App\Services\Social\Publishing\AutoPublisher;
use App\Services\Social\Publishing\FakeMetaPublishingClient;
use App\Services\Social\Publishing\MetaPublishingClient;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * F2.2 · Programar e cancelar as publicações automáticas e o publicador com o cliente da Meta
 * simulado: só sai sozinha com a etapa Programado, hora futura, o interruptor e a caixa; voltar
 * atrás, mudar a hora, desmarcar, desligar ou "Não publicar nesta rede" reprograma ou cancela; à
 * hora, sai uma só vez por rede e a publicação passa a Publicado.
 */
class AutoPublishingTest extends TestCase
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
        // Terça-feira, 10:00 em Lisboa.
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

    /** Publicação Programada para 12/11 às 12:00, no Instagram (imagem) e no Facebook (texto), com a versão aprovada. */
    private function scheduledPost(array $extra = []): EditorialPost
    {
        $post = EditorialPost::create(array_merge([
            'company_id' => $this->a->id, 'publish_date' => '2026-11-12', 'publish_time' => '12:00', 'auto_publish' => true,
            'title' => 'Menu de Natal', 'format' => 'Imagem única', 'channel' => 'instagram', 'media_format' => 'ig_feed_image',
            'stage' => EditorialPost::STAGE_PLANNING,
        ], $extra));
        EditorialPostNetwork::create(['company_id' => $this->a->id, 'editorial_post_id' => $post->id, 'network' => 'facebook', 'media_format' => 'fb_post', 'position' => 1]);
        $version = EditorialPostVersion::create(['company_id' => $this->a->id, 'editorial_post_id' => $post->id, 'number' => 1, 'caption' => 'Venha provar',
            'network_captions' => ['facebook' => 'Venha provar, no Facebook'], 'hashtags' => ['natal', '#porto'], 'status' => EditorialPostVersion::APPROVED]);
        Storage::disk('media')->put('m/1/original.jpg', 'jpeg');
        $asset = MediaAsset::create(['company_id' => $this->a->id, 'kind' => 'image', 'disk' => 'media', 'dir' => 'm/1', 'original_name' => 'menu.jpg',
            'extension' => 'jpg', 'mime' => 'image/jpeg', 'size_bytes' => 4, 'sha256' => hash('sha256', 'm1'), 'status' => MediaAsset::READY]);
        DB::table('editorial_post_version_media')->insert(['version_id' => $version->id, 'media_asset_id' => $asset->id, 'position' => 0, 'role' => 'item']);
        $post->forceFill(['current_version_id' => $version->id, 'approved_version_id' => $version->id])->save();
        $post->forceFill(['stage' => $extra['stage'] ?? EditorialPost::STAGE_SCHEDULED])->save();

        return $post->fresh();
    }

    private function publications(EditorialPost $post): array
    {
        return SocialPublication::where('editorial_post_id', $post->id)->orderBy('network')->get()
            ->mapWithKeys(fn (SocialPublication $p) => [$p->network => $p->status])->all();
    }

    public function test_scheduling_creates_one_publication_per_network_at_the_planned_time(): void
    {
        $post = $this->scheduledPost();
        $this->assertSame(['facebook' => 'scheduled', 'instagram' => 'scheduled'], $this->publications($post));
        $due = CarbonImmutable::parse('2026-11-12 12:00', 'Europe/Lisbon')->utc();
        $this->assertTrue(SocialPublication::where('network', 'instagram')->sole()->scheduled_for->equalTo($due));

        // Mudar a hora reprograma; voltar a Produção cancela; voltar a Programado agenda de novo.
        $post->update(['publish_time' => '18:30']);
        $this->assertTrue(SocialPublication::where('network', 'instagram')->sole()->scheduled_for->equalTo($due->addHours(6)->addMinutes(30)));
        $post->forceFill(['stage' => EditorialPost::STAGE_PRODUCTION])->save();
        $this->assertSame(['facebook' => 'cancelled', 'instagram' => 'cancelled'], $this->publications($post));
        $post->forceFill(['stage' => EditorialPost::STAGE_SCHEDULED])->save();
        $this->assertSame(['facebook' => 'scheduled', 'instagram' => 'scheduled'], $this->publications($post));
        $this->assertSame(2, SocialPublication::count(), 'sempre uma por rede');
    }

    public function test_without_time_box_or_switch_it_stays_manual(): void
    {
        $noTime = $this->scheduledPost(['publish_time' => null, 'title' => 'Sem hora']);
        $noBox = $this->scheduledPost(['auto_publish' => false, 'title' => 'Sem caixa']);
        $past = $this->scheduledPost(['publish_date' => '2026-11-10', 'publish_time' => '09:00', 'title' => 'Hora passada']);
        $this->assertSame([], $this->publications($noTime));
        $this->assertSame([], $this->publications($noBox));
        $this->assertSame([], $this->publications($past), 'não sai atrasada sozinha');

        $post = $this->scheduledPost();
        $post->update(['auto_publish' => false]);
        $this->assertSame(['facebook' => 'cancelled', 'instagram' => 'cancelled'], $this->publications($post));
        $post->update(['auto_publish' => true]);
        $this->assertSame(['facebook' => 'scheduled', 'instagram' => 'scheduled'], $this->publications($post));

        // Desligar o interruptor da empresa cancela; voltar a ligar agenda outra vez.
        $this->a->forceFill(['auto_publishing_enabled' => false])->save();
        $this->assertSame(['facebook' => 'cancelled', 'instagram' => 'cancelled'], $this->publications($post));
        $this->a->forceFill(['auto_publishing_enabled' => true])->save();
        $this->assertSame(['facebook' => 'scheduled', 'instagram' => 'scheduled'], $this->publications($post));
    }

    public function test_skipping_a_network_cancels_only_that_one(): void
    {
        $post = $this->scheduledPost();
        $post->networks()->where('network', 'facebook')->sole()->forceFill(['skipped_at' => now(), 'skip_reason' => 'Só no Instagram'])->save();
        $this->assertSame(['facebook' => 'cancelled', 'instagram' => 'scheduled'], $this->publications($post));
    }

    public function test_at_the_time_each_network_goes_out_once_and_the_post_becomes_published(): void
    {
        $post = $this->scheduledPost();
        $this->assertSame(['published' => 0, 'failed' => 0, 'cancelled' => 0, 'waiting' => 0], app(AutoPublisher::class)->runDue(), 'ainda não é hora');

        $this->travelTo(CarbonImmutable::parse('2026-11-12 12:00:30', 'Europe/Lisbon'));
        $this->assertSame(['published' => 2, 'failed' => 0, 'cancelled' => 0, 'waiting' => 0], app(AutoPublisher::class)->runDue());
        $this->assertSame(['published' => 0, 'failed' => 0, 'cancelled' => 0, 'waiting' => 0], app(AutoPublisher::class)->runDue(), 'nunca duas vezes');

        // Instagram: quota, contentor da imagem com a legenda e as hashtags, estado, publicar.
        $container = $this->meta->callsTo('createInstagramContainer')[0];
        $this->assertSame(['17841'], [$container[0]]);
        $this->assertSame('IMAGE', $container[1]['media_type']);
        $this->assertSame("Venha provar\n\n#natal #porto", $container[1]['caption']);
        $this->assertNotEmpty($container[1]['image_url']);
        $this->assertCount(1, $this->meta->callsTo('publishInstagram'));
        // Facebook: a legenda própria; a publicação de texto leva a imagem (F2.6: sai como fotografia).
        $fb = $this->meta->callsTo('publishFacebook');
        $this->assertCount(1, $fb);
        $this->assertSame(['1001', 'photo', "Venha provar, no Facebook\n\n#natal #porto"], [$fb[0][0], $fb[0][1]['type'], $fb[0][1]['message']]);
        $this->assertCount(1, $fb[0][1]['urls']);

        $post->refresh();
        $this->assertSame(EditorialPost::STAGE_PUBLISHED, $post->stage);
        $ig = $post->networks()->where('network', 'instagram')->sole();
        $this->assertMatchesRegularExpression('#^https://instagram\.simulado\.invalid/p/ig-\d+$#', (string) $ig->published_url);
        $this->assertNull($ig->published_by_user_id, 'publicada pela XPLENDOR, sem pessoa');
        $pub = SocialPublication::where('network', 'instagram')->sole();
        $this->assertSame([SocialPublication::STATUS_PUBLISHED, 1, $ig->published_url], [$pub->status, $pub->attempts, $pub->permalink]);
        $this->assertTrue(DB::table('editorial_post_events')->where('editorial_post_id', $post->id)->where('message', 'like', 'Instagram: publicada automaticamente%')->exists());
        Http::assertNothingSent();
    }

    public function test_failures_are_recorded_with_the_reason(): void
    {
        $post = $this->scheduledPost();
        $this->meta->failures['publishFacebook'] = FakeMetaPublishingClient::failure(MetaPublishingClient::KIND_PERMANENT, 'A Página não aceitou a publicação.', '100');
        $this->meta->quotaUsed = 100;
        $this->travelTo(CarbonImmutable::parse('2026-11-12 12:01', 'Europe/Lisbon'));

        $this->assertSame(['published' => 0, 'failed' => 2, 'cancelled' => 0, 'waiting' => 0], app(AutoPublisher::class)->runDue());
        $fb = SocialPublication::where('network', 'facebook')->sole();
        $ig = SocialPublication::where('network', 'instagram')->sole();
        $this->assertSame(['failed', '100', 'A Página não aceitou a publicação.'], [$fb->status, $fb->error_code, $fb->error_message]);
        $this->assertSame(['failed', 'quota'], [$ig->status, $ig->error_code]);
        $this->assertSame([], $this->meta->callsTo('createInstagramContainer'), 'sem quota, nem cria o contentor');
        $this->assertSame(EditorialPost::STAGE_SCHEDULED, $post->fresh()->stage);
        $this->assertSame(EditorialPostNetwork::STATE_PENDING, $post->networks()->where('network', 'instagram')->sole()->state(), 'continua por publicar à mão');

        // Uma que falhou só volta a agendar quando a hora muda.
        $post->update(['title' => 'Outro título']);
        $this->assertSame('failed', $ig->fresh()->status);
        $post->update(['publish_date' => '2026-11-13']);
        $this->assertSame('scheduled', $ig->fresh()->status);
    }

    public function test_a_post_changed_after_the_claim_is_cancelled_and_stories_stay_manual(): void
    {
        $post = $this->scheduledPost();
        $post->networks()->where('network', 'instagram')->sole()->update(['media_format' => 'ig_story']);
        $this->travelTo(CarbonImmutable::parse('2026-11-12 12:00', 'Europe/Lisbon'));
        DB::table('companies')->where('id', $this->a->id)->update(['auto_publishing_enabled' => false]); // sem eventos: como se mudasse a meio

        $this->assertSame(['published' => 0, 'failed' => 0, 'cancelled' => 2, 'waiting' => 0], app(AutoPublisher::class)->runDue());
        DB::table('companies')->where('id', $this->a->id)->update(['auto_publishing_enabled' => true]);
        SocialPublication::query()->update(['status' => 'scheduled']);
        app(AutoPublisher::class)->runDue();
        $this->assertSame('As Stories ainda não saem sozinhas: publique à mão e marque como publicada.',
            SocialPublication::where('network', 'instagram')->sole()->error_message);
    }

    public function test_the_job_runs_every_minute_and_does_nothing_when_disabled(): void
    {
        $event = collect(app(Schedule::class)->events())->firstWhere('description', 'social-publish-due');
        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        config(['scheduler.external_jobs' => false]);
        $this->assertTrue($event->filtersPass($this->app), 'com o simulado é interna');

        $this->scheduledPost();
        $this->travelTo(CarbonImmutable::parse('2026-11-12 12:00', 'Europe/Lisbon'));
        config(['social_publishing.driver' => 'disabled']);
        $this->assertSame(['skipped' => true], app(PublishDueSocialPublicationsJob::class)->handle(app(AutoPublisher::class)));
        $this->assertSame(0, SocialPublication::where('status', 'published')->count());

        config(['social_publishing.driver' => 'fake']);
        $this->assertSame(2, app(PublishDueSocialPublicationsJob::class)->handle(app(AutoPublisher::class))['published']);
    }
}
