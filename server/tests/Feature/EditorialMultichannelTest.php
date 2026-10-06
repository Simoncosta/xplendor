<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\ContentReviewLink;
use App\Models\EditorialMonth;
use App\Models\EditorialPost;
use App\Models\EditorialPostMetric;
use App\Models\EditorialPostNetwork;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Editorial\EditorialPublishingService;
use App\Services\Editorial\NetworkFormats;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Publicação multicanal: Instagram e/ou Facebook com um só conteúdo e uma só aprovação;
 * formatos validados por rede pela regra única (NetworkFormats); legenda própria opcional
 * por rede; publicação, "Não publicar nesta rede" e números por rede; Análise a contar da
 * última rede; link de aprovação e feed com as duas redes.
 */
class EditorialMultichannelTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;
    private User $userA;
    private User $adminA;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00:00', 'Europe/Lisbon'));
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->a = Company::create(['nipc' => '500097001', 'fiscal_name' => 'Quebom Lda', 'trade_name' => 'Quebom', 'plan_id' => $planId, 'subscription_status' => 'active',
            'content_sector_id' => \App\Models\ContentSector::where('slug', 'restauracao')->value('id')]);
        CompanyModule::create(['company_id' => $this->a->id, 'module_key' => 'linha_editorial']);
        EditorialMonth::create(['company_id' => $this->a->id, 'year' => 2026, 'month' => 11, 'state' => EditorialMonth::OPEN]);
        $this->userA = User::factory()->create(['company_id' => $this->a->id, 'role' => 'user']);
        $this->adminA = User::factory()->create(['company_id' => $this->a->id, 'role' => 'admin']);
    }

    private function url(string $suffix): string
    {
        return "/api/v1/companies/{$this->a->id}/editorial{$suffix}";
    }

    private function as(User $u): self
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u, 'sanctum');
    }

    private function asset(string $kind = 'image', int $ms = 0): MediaAsset
    {
        $dir = 'company_' . $this->a->id . '/' . uniqid('a', true);
        Storage::disk('media')->put("{$dir}/thumb.webp", 'x');

        return MediaAsset::create(['company_id' => $this->a->id, 'kind' => $kind, 'disk' => 'media', 'dir' => $dir, 'original_name' => 'f', 'extension' => 'jpg',
            'mime' => 'image/jpeg', 'size_bytes' => 1, 'width' => 1080, 'height' => 1350, 'duration_ms' => $ms ?: null, 'sha256' => hash('sha256', $dir),
            'variants' => ['thumb' => 'thumb.webp'], 'status' => MediaAsset::READY]);
    }

    private function create(array $networks, array $extra = [])
    {
        return $this->as($this->userA)->postJson($this->url('/posts'), array_merge([
            'title' => 'Menu de São Martinho', 'publish_date' => '2026-11-11', 'publish_time' => '09:00', 'channel' => 'social',
            'format' => 'Carrossel', 'networks' => $networks,
        ], $extra));
    }

    /** Publicação nas duas redes, com legenda (e outra no Facebook), duas imagens e enviada ao cliente. */
    private function sentToClient(): EditorialPost
    {
        $this->create(['instagram' => 'ig_carousel', 'facebook' => 'fb_photos'])->assertOk();
        $p = EditorialPost::where('title', 'Menu de São Martinho')->sole();
        $p->forceFill(['stage' => EditorialPost::STAGE_PRODUCTION])->save();
        $this->as($this->userA)->putJson($this->url("/posts/{$p->id}/content"), ['caption' => 'O menu de São Martinho.', 'network_captions' => ['facebook' => 'No Facebook: o menu de São Martinho.']])->assertOk();
        $this->as($this->userA)->putJson($this->url("/posts/{$p->id}/media"), ['items' => [$this->asset()->id, $this->asset()->id], 'cover_id' => null])->assertOk();
        $this->as($this->userA)->postJson($this->url("/posts/{$p->id}/move"), ['stage' => 'client_review'])->assertOk();

        return $p->fresh();
    }

    private function mark(EditorialPost $p, string $network, string $url, string $at)
    {
        return $this->as($this->userA)->postJson($this->url("/posts/{$p->id}/published"), ['network' => $network, 'url' => $url, 'published_at' => $at]);
    }

    // ── Redes e formatos (regra única) ───────────────────────────────────────

    public function test_networks_and_formats_validated_by_the_single_rule(): void
    {
        $this->create(['facebook' => 'ig_carousel'])->assertStatus(422)->assertJsonPath('message', 'O formato "Carrossel" não existe no Facebook. Escolha um dos formatos do Facebook.');
        $this->create(['instagram' => 'ig_carousel', 'facebook' => 'fb_reel'])->assertStatus(422)->assertJsonPath('message', NetworkFormats::INCOMPATIBLE['ig_carousel|fb_reel']);
        $this->create([])->assertStatus(422)->assertJsonPath('message', 'Escolha pelo menos uma rede.');
        $this->create(['tiktok' => null])->assertStatus(422);

        $cal = $this->create(['instagram' => 'ig_carousel', 'facebook' => 'fb_photos'])->assertOk()->json('data.posts');
        $p = EditorialPost::where('title', 'Menu de São Martinho')->sole();
        $this->assertSame(['social', ['instagram', 'facebook']], [$p->channel, $p->networkNames()]);
        $this->assertSame([['instagram', 'ig_carousel', 'pending'], ['facebook', 'fb_photos', 'pending']],
            array_map(fn ($n) => [$n['network'], $n['media_format'], $n['state']], $cal[0]['networks']));

        // A tabela vem da mesma regra (o ecrã recebe-a).
        $table = $this->as($this->userA)->getJson($this->url('/formats'))->assertOk()->json('data');
        $this->assertSame(NetworkFormats::EQUIVALENTS['ig_carousel|fb_photos'], $table['equivalents']['ig_carousel|fb_photos']);
        $this->assertSame('fb_reel', $table['suggest']['ig_reel']);
    }

    public function test_media_validated_per_network_and_carousel_with_videos(): void
    {
        $this->create(['instagram' => 'ig_carousel', 'facebook' => 'fb_photos'])->assertOk();
        $p = EditorialPost::where('title', 'Menu de São Martinho')->sole();
        $p->forceFill(['stage' => EditorialPost::STAGE_PRODUCTION])->save();
        $this->as($this->userA)->putJson($this->url("/posts/{$p->id}/content"), ['caption' => 'Legenda'])->assertOk();

        // Duas imagens: válido nas duas redes, com o aviso do equivalente no Facebook.
        $v = $this->as($this->userA)->putJson($this->url("/posts/{$p->id}/media"), ['items' => [$this->asset()->id, $this->asset()->id], 'cover_id' => null])->assertOk()->json('data.media_validation');
        $this->assertSame([], $v['errors']);
        $this->assertContains(NetworkFormats::EQUIVALENTS['ig_carousel|fb_photos'], $v['warnings']);

        // Carrossel com vídeo: o Instagram aceita, o Facebook não (erro, bloqueia o envio).
        $v = $this->as($this->userA)->putJson($this->url("/posts/{$p->id}/media"), ['items' => [$this->asset()->id, $this->asset('video', 20000)->id], 'cover_id' => null])->assertOk()->json('data.media_validation');
        $this->assertSame([], $v['by_network']['instagram']['errors']);
        $this->assertSame(['Facebook: O Facebook não junta vídeos com fotografias: retire os vídeos ou escolha "Vídeo".'], $v['errors']);
        $this->as($this->userA)->postJson($this->url("/posts/{$p->id}/move"), ['stage' => 'client_review'])->assertStatus(422);
    }

    // ── Uma aprovação para as duas redes ─────────────────────────────────────

    public function test_one_approval_covers_both_networks_and_captions(): void
    {
        $p = $this->sentToClient();
        $version = $p->currentVersion;
        $this->assertSame(['instagram' => 'ig_carousel', 'facebook' => 'fb_photos'], $version->media_formats);
        $this->assertSame(['O menu de São Martinho.', 'No Facebook: o menu de São Martinho.'], [$version->captionFor('instagram'), $version->captionFor('facebook')]);

        // O link de aprovação mostra as duas redes, cada uma com a sua legenda.
        $link = $this->as($this->userA)->postJson($this->url('/review-links'), ['title' => 'Novembro, semana 2', 'post_ids' => [$p->id]])->assertStatus(201)->json('data');
        $token = substr($link['url'], strrpos($link['url'], '#') + 1);
        $this->app['auth']->forgetGuards();
        $item = $this->withHeaders(['X-Review-Token' => $token])->getJson('/api/public/review')->assertOk()->json('data.items.0');
        $this->assertSame([['instagram', 'ig_carousel', 'O menu de São Martinho.'], ['facebook', 'fb_photos', 'No Facebook: o menu de São Martinho.']],
            array_map(fn ($n) => [$n['network'], $n['media_format'], $n['caption']], $item['networks']));

        // Uma decisão aprova as duas redes.
        $this->as($this->adminA)->postJson($this->url("/posts/{$p->id}/approve"))->assertOk()->assertJsonPath('data.post.stage', 'scheduled');
        $this->assertSame(1, DB::table('editorial_post_reviews')->where('editorial_post_id', $p->id)->count());

        // Mudar o formato depois de aprovada é conteúdo: nova versão e nova aprovação.
        $this->as($this->userA)->putJson($this->url("/posts/{$p->id}"), ['title' => $p->title, 'publish_date' => '2026-11-11', 'channel' => 'social', 'format' => 'Carrossel',
            'networks' => ['instagram' => 'ig_feed_image', 'facebook' => 'fb_photos']])->assertOk();
        $p->refresh();
        $this->assertSame([EditorialPost::STAGE_PRODUCTION, 2], [$p->stage, $p->currentVersion->number]);
    }

    // ── Publicação por rede ──────────────────────────────────────────────────

    public function test_publish_per_network_skip_and_analysis_from_the_last(): void
    {
        $p = $this->sentToClient();
        $this->as($this->adminA)->postJson($this->url("/posts/{$p->id}/approve"))->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00:00', 'Europe/Lisbon'));

        // Sem indicar a rede, numa publicação com duas: recusado.
        $this->as($this->userA)->postJson($this->url("/posts/{$p->id}/published"), ['url' => 'https://www.instagram.com/p/A/', 'published_at' => '2026-11-11 09:05'])->assertStatus(422);
        $res = $this->mark($p, 'instagram', 'https://www.instagram.com/p/A/', '2026-11-11 09:05')->assertOk();
        $this->assertSame(['scheduled', 1, 2], [$res->json('data.post.stage'), $res->json('data.publishing.published_count'), $res->json('data.publishing.active_count')]);
        $this->assertTrue($p->fresh()->isOverdue(), 'Atrasada enquanto faltar o Facebook.');
        $this->mark($p, 'facebook', 'https://www.instagram.com/p/A/', '2026-11-11 09:05')->assertStatus(422)->assertJsonValidationErrors('url');

        // "Não publicar nesta rede": motivo obrigatório; não pede nova aprovação; fica Publicado.
        $this->as($this->userA)->postJson($this->url("/posts/{$p->id}/skip-network"), ['network' => 'facebook', 'reason' => ''])->assertStatus(422);
        $approved = $p->fresh()->approved_version_id;
        $res = $this->as($this->userA)->postJson($this->url("/posts/{$p->id}/skip-network"), ['network' => 'facebook', 'reason' => 'A Página está em manutenção.'])->assertOk();
        $this->assertSame(['published', 'skipped', 'A Página está em manutenção.'], [$res->json('data.post.stage'), $res->json('data.post.networks.1.state'), $res->json('data.post.networks.1.skip_reason')]);
        $this->assertSame($approved, $p->fresh()->approved_version_id);
        $this->assertFalse($p->fresh()->isOverdue());
    }

    public function test_cannot_skip_the_last_network_and_analysis_counts_from_the_last(): void
    {
        $p = $this->sentToClient();
        $this->as($this->adminA)->postJson($this->url("/posts/{$p->id}/approve"))->assertOk();
        $this->as($this->userA)->postJson($this->url("/posts/{$p->id}/skip-network"), ['network' => 'facebook', 'reason' => 'Sem Página.'])->assertOk();
        $this->as($this->userA)->postJson($this->url("/posts/{$p->id}/skip-network"), ['network' => 'instagram', 'reason' => 'Também não.'])->assertStatus(422);

        // Volta a publicar no Facebook (marcar desfaz a dispensa) e conta da última rede.
        $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00:00', 'Europe/Lisbon'));
        $this->mark($p, 'instagram', 'https://www.instagram.com/p/A/', '2026-11-11 09:00')->assertOk()->assertJsonPath('data.post.stage', 'published');
        $this->travelTo(CarbonImmutable::parse('2026-11-14 12:00:00', 'Europe/Lisbon'));
        $this->mark($p, 'facebook', 'https://www.facebook.com/quebom/posts/1', '2026-11-14 11:00')->assertOk()->assertJsonPath('data.post.networks.1.state', 'published');

        $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00', 'Europe/Lisbon'));
        $this->assertSame(0, app(EditorialPublishingService::class)->autoAnalysis(), '7 dias do Instagram, mas não do Facebook.');
        $this->travelTo(CarbonImmutable::parse('2026-11-21 11:30:00', 'Europe/Lisbon'));
        $this->assertSame(1, app(EditorialPublishingService::class)->autoAnalysis());
        $this->assertSame(EditorialPost::STAGE_ANALYSIS, $p->fresh()->stage);
    }

    public function test_metrics_and_month_results_per_network(): void
    {
        $p = $this->sentToClient();
        $this->as($this->adminA)->postJson($this->url("/posts/{$p->id}/approve"))->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-11-12 12:00:00', 'Europe/Lisbon'));
        $this->mark($p, 'instagram', 'https://www.instagram.com/p/A/', '2026-11-11 09:00')->assertOk();

        // Facebook ainda por publicar: sem números.
        $this->as($this->userA)->putJson($this->url("/posts/{$p->id}/results"), ['network' => 'facebook', 'measured_on' => '2026-11-12', 'reach' => 10])->assertStatus(422);
        $this->mark($p, 'facebook', 'https://www.facebook.com/quebom/posts/1', '2026-11-11 09:10')->assertOk();
        $this->as($this->userA)->putJson($this->url("/posts/{$p->id}/results"), ['network' => 'instagram', 'measured_on' => '2026-11-12', 'reach' => 1000, 'interactions' => 50])->assertOk();
        $res = $this->as($this->userA)->putJson($this->url("/posts/{$p->id}/results"), ['network' => 'facebook', 'measured_on' => '2026-11-12', 'reach' => 400, 'interactions' => 2, 'worked' => 'O carrossel.'])->assertOk();
        $this->assertEquals([5, 0.5], [$res->json('data.results.networks.instagram.engagement_rate'), $res->json('data.results.networks.facebook.engagement_rate')]);
        $this->assertSame(['instagram', 'facebook'], EditorialPostMetric::where('editorial_post_id', $p->id)->where('metric', 'reach')->orderBy('id')->pluck('network')->all());

        $rows = $this->as($this->userA)->getJson($this->url('/results') . '?month=2026-11')->assertOk()->json('data.rows');
        $this->assertSame([['instagram', 'ig_carousel', 1000], ['facebook', 'fb_photos', 400]], array_map(fn ($r) => [$r['network'], $r['media_format'], $r['reach']], $rows));

        $card = collect($this->as($this->userA)->getJson($this->url('/board') . '?month=2026-11')->json('data.posts'))->firstWhere('id', $p->id);
        $this->assertSame(['instagram', 'facebook'], array_column($card['results'], 'network'));
    }

    // ── Feed ─────────────────────────────────────────────────────────────────

    public function test_feed_has_the_instagram_grid_and_the_facebook_timeline(): void
    {
        $p = $this->sentToClient();
        $this->create(['instagram' => 'ig_reel'], ['title' => 'Só no Instagram', 'format' => 'Reels', 'publish_date' => '2026-11-20'])->assertOk();
        $feed = $this->as($this->userA)->getJson($this->url('/grid'))->assertOk()->json('data');

        $this->assertSame(['Só no Instagram', 'Menu de São Martinho'], array_column($feed['upcoming'], 'title'));
        $this->assertSame(['Menu de São Martinho'], array_column($feed['facebook']['upcoming'], 'title'));
        $this->assertSame(['fb_photos', 'No Facebook: o menu de São Martinho.', 2], [$feed['facebook']['upcoming'][0]['media_format'],
            $feed['facebook']['upcoming'][0]['caption'], count($feed['facebook']['upcoming'][0]['media']['items'])]);
        $this->assertNotNull(EditorialPostNetwork::where('editorial_post_id', $p->id)->where('network', 'facebook')->first());
        $this->assertSame(0, ContentReviewLink::count());
    }
}
