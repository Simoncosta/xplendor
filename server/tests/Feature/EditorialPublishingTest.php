<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\EditorialPublishingWatchJob;
use App\Jobs\EditorialTodayJob;
use App\Models\Alert;
use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\ContentReviewNotification;
use App\Models\EditorialPost;
use App\Models\EditorialPostMetric;
use App\Models\ImpersonationSession;
use App\Models\User;
use App\Services\Editorial\EditorialPublishingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Linha Editorial, F3d: Publicado e Análise (à mão). Lista de hoje, atrasadas (aviso uma
 * vez), marcar como publicada (link validado, hora real, permissões por modo, pessoa real),
 * passagem automática a Análise aos 7 dias, números com origem, taxa de envolvimento,
 * resultados do mês e tenancy.
 */
class EditorialPublishingTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;
    private Company $b;
    private Company $x;
    private User $userA;
    private User $adminA;
    private User $userB;
    private User $root;

    protected function setUp(): void
    {
        parent::setUp();
        // Terça-feira, 10:00 em Lisboa.
        $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00:00', 'Europe/Lisbon'));
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->a = Company::create(['nipc' => '500095001', 'fiscal_name' => 'Quebom Lda', 'trade_name' => 'Quebom', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->b = Company::create(['nipc' => '500095002', 'fiscal_name' => 'Outra Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->x = Company::create(['nipc' => '500095003', 'fiscal_name' => 'XPLENDOR', 'plan_id' => $planId, 'subscription_status' => 'active']);
        foreach ([$this->a, $this->b] as $c) {
            CompanyModule::create(['company_id' => $c->id, 'module_key' => 'linha_editorial']);
        }
        $this->userA = User::factory()->create(['company_id' => $this->a->id, 'role' => 'user']);
        $this->adminA = User::factory()->create(['company_id' => $this->a->id, 'role' => 'admin']);
        $this->userB = User::factory()->create(['company_id' => $this->b->id, 'role' => 'user']);
        $this->root = User::factory()->create(['company_id' => $this->x->id, 'role' => 'root', 'name' => 'Ana Equipa']);
        config(['quotes.team_company_id' => $this->x->id]);
    }

    private function url(Company $c, string $suffix): string
    {
        return "/api/v1/companies/{$c->id}/editorial{$suffix}";
    }

    private function as(User $u): self
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u, 'sanctum');
    }

    private function makePost(Company $c, array $extra = []): EditorialPost
    {
        $p = EditorialPost::create(array_merge([
            'company_id' => $c->id, 'publish_date' => '2026-11-10', 'title' => 'Menu de Natal', 'format' => 'Imagem única',
            'media_format' => 'ig_feed_image', 'channel' => 'instagram', 'stage' => EditorialPost::STAGE_SCHEDULED,
        ], $extra));
        // Já publicada (ou em Análise): a rede fica publicada no dia previsto, às 10:00.
        if (in_array($p->stage, [EditorialPost::STAGE_PUBLISHED, EditorialPost::STAGE_ANALYSIS], true) && $p->channel !== 'site') {
            $p->networks()->update(['published_at' => CarbonImmutable::parse($p->publish_date->toDateString() . ' 10:00', 'Europe/Lisbon')->utc(), 'published_url' => 'https://www.instagram.com/p/X/']);
        }

        return $p->fresh();
    }

    private function network(EditorialPost $p, string $network = 'instagram'): \App\Models\EditorialPostNetwork
    {
        return $p->networks()->where('network', $network)->sole();
    }

    private function mark(User $u, EditorialPost $p, string $url, ?string $at = '2026-11-10 09:45')
    {
        return $this->as($u)->postJson($this->url(Company::find($p->company_id), "/posts/{$p->id}/published"), ['url' => $url, 'published_at' => $at]);
    }

    // ── Hoje e atrasadas ─────────────────────────────────────────────────────

    public function test_today_list_and_overdue_flag(): void
    {
        $morning = $this->makePost($this->a, ['title' => 'De manhã', 'publish_time' => '09:00']);
        $evening = $this->makePost($this->a, ['title' => 'À noite', 'publish_time' => '21:00']);
        $noTime = $this->makePost($this->a, ['title' => 'Sem hora']);
        $yesterday = $this->makePost($this->a, ['title' => 'De ontem', 'publish_date' => '2026-11-09']);
        $this->makePost($this->a, ['title' => 'Amanhã', 'publish_date' => '2026-11-11']);
        $this->makePost($this->a, ['title' => 'Site', 'channel' => 'site', 'format' => 'Artigo', 'media_format' => null]);
        $this->makePost($this->a, ['title' => 'Já publicada', 'stage' => EditorialPost::STAGE_PUBLISHED]);
        $this->makePost($this->b, ['title' => 'De outra empresa']);

        $res = $this->as($this->userA)->getJson($this->url($this->a, '/today'))->assertOk();
        $this->assertSame(['De manhã', 'À noite', 'Sem hora'], array_column($res->json('data.today'), 'title'));
        $this->assertSame(['De ontem', 'De manhã'], array_column($res->json('data.overdue'), 'title'));
        $this->assertTrue($res->json('data.today.0.can_mark'));
        $this->assertSame([true, false, false, true], [$morning->isOverdue(), $evening->isOverdue(), $noTime->isOverdue(), $yesterday->isOverdue()]);

        // Calendário e Kanban mostram "Atrasada".
        $board = collect($this->as($this->userA)->getJson($this->url($this->a, '/board') . '?month=2026-11')->assertOk()->json('data.posts'))->keyBy('title');
        $this->assertSame([true, false, '09:00'], [$board['De manhã']['overdue'], $board['À noite']['overdue'], $board['De manhã']['publish_time']]);
        $this->a->forceFill(['content_sector_id' => \App\Models\ContentSector::where('slug', 'restauracao')->value('id')])->save();
        $cal = collect($this->as($this->userA)->getJson($this->url($this->a, '/calendar'))->assertOk()->json('data.posts'))->keyBy('title');
        $this->assertSame([true, false], [$cal['De ontem']['overdue'], $cal['Amanhã']['overdue']]);

        // Fim do dia: a sem hora também fica atrasada.
        $this->travelTo(CarbonImmutable::parse('2026-11-11 00:01:00', 'Europe/Lisbon'));
        $this->assertTrue($noTime->fresh()->isOverdue());
    }

    public function test_overdue_alert_once_and_to_the_right_place(): void
    {
        $late = $this->makePost($this->a, ['title' => 'Atrasada', 'publish_time' => '08:00']);
        $this->makePost($this->a, ['title' => 'Mais logo', 'publish_time' => '18:00']);
        $this->makePost($this->a, ['title' => 'Site', 'channel' => 'site', 'format' => 'Artigo', 'media_format' => null, 'publish_date' => '2026-11-01']);

        $first = app(EditorialPublishingWatchJob::class)->handle(app(EditorialPublishingService::class), app(\App\Services\ContentReview\ContentReviewNotifier::class));
        $this->assertSame(1, $first['overdue_alerts']);
        $again = (new EditorialPublishingWatchJob())->handle(app(EditorialPublishingService::class), app(\App\Services\ContentReview\ContentReviewNotifier::class));
        $this->assertSame(0, $again['overdue_alerts']);
        $alert = Alert::where('company_id', $this->a->id)->sole();
        $this->assertSame(['urgent', 'Publicação atrasada: Atrasada'], [$alert->type, $alert->title]);
        $this->assertNotNull($late->fresh()->overdue_alerted_at);
        $this->assertSame(1, ContentReviewNotification::where('company_id', $this->a->id)->count(), 'Também vai no resumo por email.');

        // Modo "Produção pela equipa": o aviso vai para o sino da equipa.
        $this->a->forceFill(['content_production_mode' => 'team'])->save();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 18:30:00', 'Europe/Lisbon'));
        (new EditorialPublishingWatchJob())->handle(app(EditorialPublishingService::class), app(\App\Services\ContentReview\ContentReviewNotifier::class));
        $this->assertTrue(Alert::where('company_id', $this->x->id)->where('title', 'Quebom: Publicação atrasada: Mais logo')->exists());
    }

    public function test_today_job_lists_the_day_per_company(): void
    {
        $this->makePost($this->a, ['title' => 'Menu', 'publish_time' => '12:00']);
        $this->makePost($this->a, ['title' => 'Bastidores', 'channel' => 'facebook', 'media_format' => 'fb_photos', 'publish_time' => '09:30']);
        $this->makePost($this->a, ['title' => 'Amanhã', 'publish_date' => '2026-11-11']);
        $this->makePost($this->b, ['title' => 'Da B']);

        $done = (new EditorialTodayJob())->handle(app(\App\Services\ContentReview\ContentReviewNotifier::class));
        $this->assertSame(['companies' => 2, 'posts' => 3], $done);
        $alert = Alert::where('company_id', $this->a->id)->sole();
        $this->assertSame('Para publicar hoje: 2 publicações', $alert->title);
        $this->assertSame('09:30 Bastidores (Facebook); 12:00 Menu (Instagram).', $alert->message);
    }

    // ── Marcar como publicada ────────────────────────────────────────────────

    public function test_mark_published_validates_the_link_and_records_the_real_person(): void
    {
        $ig = $this->makePost($this->a);
        $fb = $this->makePost($this->a, ['title' => 'No Facebook', 'channel' => 'facebook', 'media_format' => 'fb_photos']);

        foreach (['http://www.instagram.com/p/ABC/', 'https://www.instagram.com/', 'https://instagram.com.evil.test/p/ABC/', 'https://www.facebook.com/quebom/posts/1',
            'javascript:alert(1)', 'nada'] as $bad) {
            $this->mark($this->userA, $ig, $bad)->assertStatus(422)->assertJsonValidationErrors('url');
        }
        $this->mark($this->userA, $fb, 'https://www.instagram.com/p/ABC/')->assertStatus(422)->assertJsonValidationErrors('url');
        $this->mark($this->userA, $ig, 'https://www.instagram.com/p/ABC/', '2026-11-10 23:00')->assertStatus(422)->assertJsonValidationErrors('published_at');
        $this->mark($this->userA, $ig, 'https://www.instagram.com/p/ABC/', null)->assertStatus(422)->assertJsonValidationErrors('published_at');

        $res = $this->mark($this->userA, $ig, 'https://www.instagram.com/p/ABC123/', '2026-11-10 09:45')->assertOk();
        $ig->refresh();
        $n = $this->network($ig);
        $this->assertSame([EditorialPost::STAGE_PUBLISHED, 'https://www.instagram.com/p/ABC123/', '2026-11-10 09:45', $this->userA->id, null],
            [$ig->stage, $n->published_url, $n->published_at->setTimezone('Europe/Lisbon')->format('Y-m-d H:i'), $n->published_by_user_id, $n->published_by_impersonator_id]);
        $this->assertSame([$this->userA->name, 'https://www.instagram.com/p/ABC123/', 'published'],
            [$res->json('data.post.networks.0.published_by'), $res->json('data.post.networks.0.published_url'), $res->json('data.post.networks.0.state')]);
        $this->assertSame('publicada', $ig->status);
        $this->mark($this->userA, $fb, 'https://m.facebook.com/quebom/posts/123', '2026-11-10 09:00')->assertOk();

        // Corrigir o link depois de publicada não muda a etapa; no Site, nunca.
        $this->mark($this->userA, $ig, 'https://www.instagram.com/p/XYZ/', '2026-11-10 09:50')->assertOk()->assertJsonPath('data.post.stage', 'published');
        $site = $this->makePost($this->a, ['title' => 'Site', 'channel' => 'site', 'format' => 'Artigo', 'media_format' => null]);
        $this->mark($this->userA, $site, 'https://www.instagram.com/p/ABC/')->assertStatus(422);
        $draft = $this->makePost($this->a, ['title' => 'Em produção', 'stage' => EditorialPost::STAGE_PRODUCTION]);
        $this->mark($this->userA, $draft, 'https://www.instagram.com/p/ABC/')->assertStatus(422);
    }

    public function test_mark_published_permissions_by_production_mode(): void
    {
        $p = $this->makePost($this->a);
        $this->a->forceFill(['content_production_mode' => 'team'])->save();

        $this->mark($this->adminA, $p, 'https://www.instagram.com/p/ABC/')->assertStatus(403);
        $this->as($this->adminA)->getJson($this->url($this->a, '/today'))->assertOk()->assertJsonPath('data.today.0.can_mark', false);

        // A equipa em sessão como cliente: fica registada a pessoa real.
        $this->app['auth']->forgetGuards();
        $nt = $this->userA->createToken('impersonation', ['impersonation'], now()->addMinutes(30));
        ImpersonationSession::create(['root_id' => $this->root->id, 'target_user_id' => $this->userA->id, 'company_id' => $this->a->id,
            'token_id' => $nt->accessToken->getKey(), 'ip' => '127.0.0.1', 'user_agent' => 'test', 'started_at' => now()]);
        $res = $this->withHeaders(['Authorization' => 'Bearer ' . $nt->plainTextToken, 'Accept' => 'application/json'])
            ->postJson($this->url($this->a, "/posts/{$p->id}/published"), ['url' => 'https://www.instagram.com/p/ABC/', 'published_at' => '2026-11-10 09:45'])->assertOk();
        $n = $this->network($p);
        $this->assertSame([$this->userA->id, $this->root->id], [$n->published_by_user_id, $n->published_by_impersonator_id]);
        $this->assertSame('Ana Equipa (equipa XPLENDOR)', $res->json('data.post.networks.0.published_by'));
    }

    // ── Análise ──────────────────────────────────────────────────────────────

    public function test_moves_to_analysis_seven_days_after_publishing(): void
    {
        $p = $this->makePost($this->a);
        $this->mark($this->userA, $p, 'https://www.instagram.com/p/ABC/', '2026-11-10 09:45')->assertOk();
        $other = $this->makePost($this->a, ['title' => 'Sem hora real', 'stage' => EditorialPost::STAGE_PUBLISHED]);

        $this->travelTo(CarbonImmutable::parse('2026-11-17 09:40:00', 'Europe/Lisbon'));
        $this->assertSame(0, app(EditorialPublishingService::class)->autoAnalysis());
        $this->travelTo(CarbonImmutable::parse('2026-11-17 09:46:00', 'Europe/Lisbon'));
        $this->assertSame(1, app(EditorialPublishingService::class)->autoAnalysis());
        $this->assertSame([EditorialPost::STAGE_ANALYSIS, 'otimizada'], [$p->fresh()->stage, $p->fresh()->status]);
        $this->assertSame(EditorialPost::STAGE_PUBLISHED, $other->fresh()->stage);
        $this->assertSame('Passou a Análise ao fim de 7 dias de publicada.', DB::table('editorial_post_events')->where('editorial_post_id', $p->id)->orderByDesc('id')->value('message'));

        // À mão também se passa a Análise.
        $this->as($this->userA)->postJson($this->url($this->a, "/posts/{$other->id}/move"), ['stage' => 'analysis'])->assertOk();
    }

    public function test_results_with_source_engagement_rate_and_notes(): void
    {
        $p = $this->makePost($this->a, ['stage' => EditorialPost::STAGE_PUBLISHED, 'pillar' => 'Produtos da época']);
        $save = fn (array $d, ?User $u = null) => $this->as($u ?? $this->userA)->putJson($this->url($this->a, "/posts/{$p->id}/results"), $d);

        $save(['measured_on' => '2026-11-11', 'reach' => -1])->assertStatus(422);
        $save(['measured_on' => '2026-11-12', 'reach' => 10])->assertStatus(422);
        $save(['reach' => 10])->assertStatus(422);

        $res = $save(['measured_on' => '2026-11-10', 'reach' => 3000, 'interactions' => 1, 'likes' => 1, 'video_views' => 999, 'worked' => 'A foto do prato.', 'change' => ''])->assertOk();
        // 1 ÷ 3000 × 100 = 0,0333…: guardada com precisão, não arredondada para 0.
        $this->assertSame(0.0333, $res->json('data.results.networks.instagram.engagement_rate'));
        $this->assertSame(['value' => 3000, 'source' => 'manual', 'measured_on' => '2026-11-10'], $res->json('data.results.networks.instagram.values.reach'));
        $this->assertNull($res->json('data.results.networks.instagram.values.video_views'), 'Visualizações só nos vídeos.');
        $this->assertSame(['A foto do prato.', null], [$res->json('data.results.worked'), $res->json('data.results.change')]);

        // Sem alcance (zero ou vazio): sem taxa.
        $this->assertNull($save(['measured_on' => '2026-11-10', 'reach' => 0, 'interactions' => 5])->assertOk()->json('data.results.networks.instagram.engagement_rate'));
        $this->assertNull($save(['measured_on' => '2026-11-10', 'reach' => null, 'interactions' => 5])->assertOk()->json('data.results.networks.instagram.engagement_rate'));
        $this->assertSame(0, EditorialPostMetric::where('editorial_post_id', $p->id)->where('metric', 'reach')->count());
        $this->assertEquals(3, $save(['measured_on' => '2026-11-10', 'reach' => 400, 'interactions' => 12])->json('data.results.networks.instagram.engagement_rate'));
        $this->assertNull(EditorialPublishingService::engagementRate(null, 400));
        $this->assertSame(150.0, EditorialPublishingService::engagementRate(600, 400));

        // Origem: um número lido da Meta (F6) fica ao lado do manual, sem o apagar.
        EditorialPostMetric::create(['company_id' => $this->a->id, 'editorial_post_id' => $p->id, 'network' => 'instagram', 'metric' => 'reach', 'source' => 'meta', 'value' => 410, 'measured_on' => '2026-11-11']);
        $detail = $this->as($this->userA)->getJson($this->url($this->a, "/posts/{$p->id}/workflow"))->assertOk();
        $this->assertSame(['value' => 410, 'source' => 'meta', 'measured_on' => '2026-11-11'], $detail->json('data.results.networks.instagram.values.reach'));
        $this->assertSame(400, (int) EditorialPostMetric::where('editorial_post_id', $p->id)->where('metric', 'reach')->where('source', 'manual')->value('value'));

        // Vídeo: as visualizações contam.
        $reel = $this->makePost($this->a, ['title' => 'Reel', 'media_format' => 'ig_reel', 'stage' => EditorialPost::STAGE_ANALYSIS]);
        $this->as($this->userA)->putJson($this->url($this->a, "/posts/{$reel->id}/results"), ['measured_on' => '2026-11-10', 'reach' => 200, 'interactions' => 30, 'video_views' => 900])
            ->assertOk()->assertJsonPath('data.results.networks.instagram.values.video_views.value', 900)->assertJsonPath('data.results.networks.instagram.is_video', true);

        // Kanban: alcance e taxa no cartão; Programadas não aceitam resultados.
        $card = collect($this->as($this->userA)->getJson($this->url($this->a, '/board') . '?month=2026-11')->json('data.posts'))->firstWhere('id', $reel->id);
        $this->assertEquals([['network' => 'instagram', 'reach' => 200, 'engagement_rate' => 15]], $card['results']);
        $scheduled = $this->makePost($this->a, ['title' => 'Ainda programada']);
        $this->as($this->userA)->putJson($this->url($this->a, "/posts/{$scheduled->id}/results"), ['measured_on' => '2026-11-10', 'reach' => 1])->assertStatus(422);

        // Modo "Produção pela equipa": o cliente não regista resultados.
        $this->a->forceFill(['content_production_mode' => 'team'])->save();
        $save(['measured_on' => '2026-11-10', 'reach' => 1], $this->adminA)->assertStatus(403);
    }

    public function test_month_results_table(): void
    {
        $one = $this->makePost($this->a, ['title' => 'Um', 'publish_date' => '2026-11-03', 'stage' => EditorialPost::STAGE_ANALYSIS, 'pillar' => 'Bastidores']);
        $two = $this->makePost($this->a, ['title' => 'Dois', 'publish_date' => '2026-11-05', 'channel' => 'facebook', 'media_format' => 'fb_photos', 'stage' => EditorialPost::STAGE_PUBLISHED]);
        $this->makePost($this->a, ['title' => 'Programada', 'publish_date' => '2026-11-20']);
        $this->makePost($this->a, ['title' => 'Outubro', 'publish_date' => '2026-10-30', 'stage' => EditorialPost::STAGE_ANALYSIS]);
        $this->makePost($this->a, ['title' => 'Site', 'channel' => 'site', 'format' => 'Artigo', 'media_format' => null, 'stage' => EditorialPost::STAGE_PUBLISHED, 'publish_date' => '2026-11-04']);
        foreach ([['reach', 500], ['interactions', 25]] as [$m, $v]) {
            EditorialPostMetric::create(['company_id' => $this->a->id, 'editorial_post_id' => $one->id, 'network' => 'instagram', 'metric' => $m, 'source' => 'manual', 'value' => $v, 'measured_on' => '2026-11-09']);
        }

        $rows = $this->as($this->userA)->getJson($this->url($this->a, '/results') . '?month=2026-11')->assertOk()->json('data.rows');
        $this->assertSame(['Um', 'Dois'], array_column($rows, 'title'));
        $this->assertSame(['2026-11-03', 'instagram', 'ig_feed_image', 'Bastidores', 500], [$rows[0]['date'], $rows[0]['network'], $rows[0]['media_format'], $rows[0]['pillar'], $rows[0]['reach']]);
        $this->assertEquals(5, $rows[0]['engagement_rate']);
        $this->assertSame([null, null], [$rows[1]['reach'], $rows[1]['engagement_rate']]);
        $this->as($this->userA)->getJson($this->url($this->a, '/results') . '?month=novembro')->assertStatus(422);
    }

    public function test_post_time_and_pillar_on_create(): void
    {
        \App\Models\EditorialMonth::create(['company_id' => $this->a->id, 'year' => 2026, 'month' => 11, 'state' => \App\Models\EditorialMonth::OPEN]);
        $base = ['title' => 'Com hora', 'publish_date' => '2026-11-20', 'channel' => 'instagram', 'format' => 'Imagem única'];
        $this->as($this->userA)->postJson($this->url($this->a, '/posts'), $base + ['publish_time' => '25:00'])->assertStatus(422)->assertJsonPath('message', 'Indique a hora no formato HH:MM.');
        $this->as($this->userA)->postJson($this->url($this->a, '/posts'), $base + ['publish_time' => '18:30', 'pillar' => 'Bastidores'])->assertOk();
        $post = EditorialPost::where('title', 'Com hora')->sole();
        $this->assertSame(['18:30', 'Bastidores'], [$post->publish_time, $post->pillar]);
    }

    // ── Tenancy ──────────────────────────────────────────────────────────────

    public function test_tenancy(): void
    {
        $p = $this->makePost($this->a, ['stage' => EditorialPost::STAGE_PUBLISHED]);

        $this->as($this->userB)->getJson($this->url($this->a, '/today'))->assertStatus(403);
        $this->as($this->userB)->getJson($this->url($this->a, '/results') . '?month=2026-11')->assertStatus(403);
        $this->as($this->userB)->postJson($this->url($this->b, "/posts/{$p->id}/published"), ['url' => 'https://www.instagram.com/p/A/', 'published_at' => '2026-11-10 09:00'])->assertNotFound();
        $this->as($this->userB)->putJson($this->url($this->b, "/posts/{$p->id}/results"), ['measured_on' => '2026-11-10', 'reach' => 1])->assertNotFound();
        $this->assertSame([], $this->as($this->userB)->getJson($this->url($this->b, '/results') . '?month=2026-11')->json('data.rows'));
        $this->assertSame(0, EditorialPostMetric::count());
    }
}
