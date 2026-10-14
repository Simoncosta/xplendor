<?php

declare(strict_types=1);

namespace Tests\Feature\Radar;

use App\Models\AiRequest;
use App\Models\Company;
use App\Models\CompanyBrandProfile;
use App\Models\ContentSector;
use App\Models\EditorialMonth;
use App\Models\EditorialPost;
use App\Models\RadarItem;
use App\Models\RadarRelevance;
use App\Models\RadarSetting;
use App\Models\RadarSignal;
use App\Models\RadarTopic;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\Radar\Fit\RadarFitService;
use App\Services\Radar\Relevance\RadarRelevanceService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesAi;
use Tests\TestCase;

/**
 * Bússola Master, R2: a relevância por ramo (lotes, nunca uma tendência bloqueada), o encaixe
 * (limite de 3 por dia, janela, desporto só com opção, clubes, temas silenciados, temas a evitar
 * da marca), o "porque lhe interessa" (IA simulada; o modelo de frase quando a IA diz uma causa),
 * a página (módulo, tenancy, sem euros nem dados pessoais), o "Reagir" sem texto, o ignorar e o
 * silenciar, as definições e o interruptor no painel do root.
 */
class RadarSignalsTest extends TestCase
{
    use FakesAi;
    use RefreshDatabase;

    private const NOW = '2026-10-14 10:00:00';

    private Company $company;
    private Company $other;
    private User $admin;
    private User $otherAdmin;
    private int $planId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::NOW));
        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::NOW));
        Http::preventStrayRequests();
        config(['radar.enabled' => true]);
        $this->planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 9, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = $this->makeCompany('500019101', 'Tasca Exemplo', 'restauracao');
        $this->other = $this->makeCompany('500019102', 'Outra Tasca', 'restauracao');
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
        $this->otherAdmin = User::factory()->create(['company_id' => $this->other->id, 'role' => 'admin']);
        \App\Access\CompatibilityMigration::run();
        EditorialMonth::create(['company_id' => $this->company->id, 'year' => 2026, 'month' => 10, 'state' => EditorialMonth::OPEN]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    // ── Auxiliares ───────────────────────────────────────────────────────────

    private function makeCompany(string $nipc, string $name, ?string $sector, array $modules = ['linha_editorial', 'radar']): Company
    {
        $c = Company::create(['nipc' => $nipc, 'fiscal_name' => "{$name} Lda", 'trade_name' => $name, 'plan_id' => $this->planId, 'subscription_status' => 'active',
            'content_sector_id' => $sector ? ContentSector::where('slug', $sector)->value('id') : null]);
        foreach ($modules as $m) {
            app(CompanyModuleService::class)->enable($c->id, $m);
        }

        return $c;
    }

    private function topic(string $title, array $attrs = []): RadarTopic
    {
        return RadarTopic::create(array_merge([
            'kind' => 'trend', 'title' => $title, 'normalized_key' => 'trend:' . md5($title . microtime()), 'first_seen_at' => now()->subHours(2), 'last_seen_at' => now()->subHour(),
            'approx_volume' => 500, 'approx_volume_label' => '500+', 'filter_verdict' => RadarItem::VERDICT_PASSED, 'filter_reasons' => [], 'filter_flags' => [],
            'sources' => [['title' => $title, 'source_name' => 'Google Trends', 'url' => 'https://exemplo.pt/noticia', 'published_at' => now()->subHours(2)->toIso8601String()],
                ['title' => 'Título de uma notícia de terceiros', 'source_name' => 'Jornal Exemplo', 'url' => 'https://exemplo.pt/n2', 'published_at' => null]],
            'window_until' => now()->addHours(46), 'expires_at' => now()->addDays(30),
        ], $attrs));
    }

    private function relevant(RadarTopic $t, int $score = 70, string $sector = 'restauracao', string $theme = 'gastronomia'): RadarRelevance
    {
        return RadarRelevance::create(['topic_id' => $t->id, 'sector_slug' => $sector, 'relevant' => true, 'score' => $score, 'theme' => $theme]);
    }

    private function fit(?Company $c = null): array
    {
        return app(RadarFitService::class)->fit(($c ?? $this->company)->id);
    }

    private function url(string $path = '', ?Company $c = null): string
    {
        return '/api/v1/companies/' . ($c ?? $this->company)->id . '/marketing/radar' . $path;
    }

    // ── Relevância ───────────────────────────────────────────────────────────

    public function test_relevance_runs_per_sector_in_batches_and_never_sends_a_blocked_topic(): void
    {
        $this->configureAi();
        $passed = [];
        for ($i = 1; $i <= 21; $i++) {
            $passed[] = $this->topic("Feira do queijo número {$i}");
        }
        $blocked = $this->topic('Título bloqueado pelo filtro', ['filter_verdict' => RadarItem::VERDICT_BLOCKED, 'filter_reasons' => ['crime']]);
        $answer = fn (array $ids) => $this->anthropicResponse(['avaliacoes' => array_map(fn ($id) => ['id' => $id, 'relevante' => true, 'pontuacao' => 80, 'tema' => 'gastronomia', 'frase' => 'Feira gastronómica.'], $ids)]);
        // A resposta avalia as tendências que o pedido leva (o prompt traz-nas em JSON, escapado no corpo do pedido).
        Http::fake(['api.anthropic.com/*' => function ($req) use ($answer) {
            preg_match_all('/\\\\"id\\\\":(\d+)/', (string) $req->body(), $m);

            return $answer(array_map('intval', $m[1]));
        }]);

        $out = app(RadarRelevanceService::class)->evaluate();
        $this->assertSame(1, $out['ramos'], 'só o ramo com o Radar ligado (restauração)');
        $this->assertSame(2, $out['pedidos'], '21 tendências em lotes de 20');
        $this->assertSame(21, RadarRelevance::count());
        $this->assertSame(0, RadarRelevance::where('topic_id', $blocked->id)->count());
        Http::assertSent(fn ($req) => ! str_contains((string) $req->body(), 'Título bloqueado pelo filtro'));
        $this->assertGreaterThan(0, (float) RadarRelevance::sum('cost_usd'));
        $this->assertSame('claude-haiku-5-5', RadarRelevance::value('model'));

        // Uma tendência avaliada não volta a ser avaliada para o mesmo ramo.
        $this->assertSame(0, app(RadarRelevanceService::class)->evaluate()['pedidos']);
    }

    public function test_relevance_without_a_key_or_with_the_radar_off_does_nothing(): void
    {
        $this->topic('Feira do vinho');
        config(['ai.providers.anthropic.key' => '', 'ai.fake' => false]);
        $this->assertSame(0, app(RadarRelevanceService::class)->evaluate()['pedidos']);
        $this->configureAi();
        config(['radar.enabled' => false]);
        $this->assertSame(0, app(RadarRelevanceService::class)->evaluate()['pedidos']);
        Http::assertNothingSent();
    }

    // ── Encaixe ──────────────────────────────────────────────────────────────

    public function test_at_most_three_signals_per_day_counting_the_ignored_ones(): void
    {
        foreach (range(1, 5) as $i) {
            $this->relevant($this->topic("Festival de gastronomia {$i}"), 50 + $i);
        }
        $this->assertSame(3, $this->fit()['criados']);
        $this->assertSame(0, $this->fit()['criados']);
        RadarSignal::where('company_id', $this->company->id)->update(['status' => RadarSignal::IGNORED]);
        $this->assertSame(0, $this->fit()['criados'], 'os ignorados contam para os 3 do dia');

        Carbon::setTestNow(Carbon::parse('2026-10-15 09:00:00'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15 09:00:00'));
        $this->assertSame(2, $this->fit()['criados'], 'no dia seguinte, as que sobram');
    }

    public function test_the_window_is_never_in_the_past(): void
    {
        $this->relevant($this->topic('Já passou', ['window_until' => now()->subMinute()]));
        $this->relevant($this->topic('Acaba hoje', ['window_until' => now()->addHours(3)]));
        $this->fit();
        $this->assertSame(['Acaba hoje'], RadarSignal::with('topic')->get()->pluck('topic.title')->all());

        $page = $this->actingAs($this->admin, 'sanctum')->getJson($this->url())->assertOk()->json('data');
        $this->assertSame('2026-10-14', $page['signals'][0]['when']['date']);
        $this->assertTrue($page['signals'][0]['when']['is_today']);
    }

    public function test_sport_only_with_the_option_and_only_the_chosen_clubs(): void
    {
        $this->relevant($this->topic('jogo do clube azul', ['filter_flags' => ['desporto']]));
        $this->relevant($this->topic('jogo do clube verde', ['filter_flags' => ['desporto']]));
        $out = $this->fit();
        $this->assertSame(0, $out['criados']);
        $this->assertSame(2, $out['excluidos']['desporto']);

        RadarSetting::create(['company_id' => $this->company->id, 'sports_enabled' => true, 'sports_clubs' => ['Clube Verde']]);
        $out = $this->fit();
        $this->assertSame(1, $out['criados']);
        $this->assertSame(['jogo do clube verde'], RadarSignal::with('topic')->get()->pluck('topic.title')->all());
    }

    public function test_muted_themes_and_the_brand_topics_to_avoid_are_left_out(): void
    {
        $this->relevant($this->topic('Feira do queijo da serra'), 80, theme: 'feiras');
        $this->relevant($this->topic('Saldos de outono no comércio'), 70, theme: 'comercio');
        $this->relevant($this->topic('Concurso de vinhos do Douro'), 60, theme: 'vinhos');
        RadarSetting::create(['company_id' => $this->company->id, 'muted' => [['theme' => 'feiras', 'term' => null, 'title' => 'x']]]);
        CompanyBrandProfile::create(['company_id' => $this->company->id, 'topics_to_avoid' => ['saldos']]);

        $out = $this->fit();
        $this->assertSame(1, $out['criados']);
        $this->assertSame(['silenciado' => 1, 'marca_empresa' => 1], $out['excluidos']);
        $this->assertSame(['Concurso de vinhos do Douro'], RadarSignal::with('topic')->get()->pluck('topic.title')->all());
    }

    public function test_the_why_comes_from_the_simulated_ai_and_falls_back_to_the_template_on_a_cause(): void
    {
        $this->configureAi();
        $this->relevant($this->topic('Feira do queijo da serra'), 80);
        $this->relevant($this->topic('Concurso de vinhos'), 70);
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->pushResponse($this->anthropicResponse('Uma feira gastronómica próxima do que a marca comunica sobre produtos da região.'))
            ->pushResponse($this->anthropicResponse('Interessa porque as pessoas procuram vinho.'))
            ->pushResponse($this->anthropicResponse('Interessa porque as pessoas procuram vinho.'))]);

        $this->fit();
        $signals = RadarSignal::with('topic')->orderBy('id')->get();
        $this->assertSame('ai', $signals[0]->why_source);
        $this->assertSame('template', $signals[1]->why_source, 'uma causa ("porque") nunca passa');
        $this->assertStringStartsWith('Está a subir em Portugal: Concurso de vinhos', $signals[1]->why);
        $this->assertSame(2, AiRequest::where('mode', 'radar_porque')->where('company_id', $this->company->id)->count());
    }

    public function test_with_the_monthly_quota_exhausted_the_template_is_used_without_a_request(): void
    {
        $this->configureAi();
        config(['services.openai.ai_monthly_caps.radar_porque' => 0]);
        $this->relevant($this->topic('Feira do queijo'));
        $this->fit();
        $this->assertSame('template', RadarSignal::value('why_source'));
        Http::assertNothingSent();
    }

    public function test_without_a_sector_or_without_the_module_there_are_no_signals(): void
    {
        $noSector = $this->makeCompany('500019103', 'Sem Ramo', null);
        $this->relevant($this->topic('Feira do queijo'));
        $this->assertSame('Empresa sem ramo na Linha Editorial.', $this->fit($noSector)['motivo']);
        $noModule = $this->makeCompany('500019104', 'Sem Radar', 'restauracao', ['linha_editorial']);
        $this->assertSame(0, $this->fit($noModule)['criados']);
    }

    // ── Página, tenancy e ações ──────────────────────────────────────────────

    public function test_the_page_needs_the_module_and_shows_no_money_and_no_news_titles_from_trends(): void
    {
        $this->relevant($this->topic('Feira do queijo', ['filter_flags' => ['nome_pessoa']]));
        $this->fit();
        $res = $this->actingAs($this->admin, 'sanctum')->getJson($this->url())->assertOk();
        $data = $res->json('data');
        $this->assertCount(1, $data['signals']);
        $s = $data['signals'][0];
        $this->assertSame('mais de 500 pesquisas', $s['volume_label']);
        $this->assertSame('Fonte: Google Trends', $s['attribution']);
        $this->assertSame(['Nome de pessoa: não use a imagem nem o nome da pessoa para promover.'], $s['notices']);
        $this->assertNull($s['sources'][1]['title'], 'o título da notícia de terceiros não se mostra');
        $this->assertSame('Jornal Exemplo', $s['sources'][1]['source_name']);
        $body = (string) $res->getContent();
        $this->assertStringNotContainsString('€', $body);
        $this->assertStringNotContainsString('Título de uma notícia de terceiros', $body);

        $none = $this->makeCompany('500019105', 'Sem Módulo', 'restauracao', ['linha_editorial']);
        $u = User::factory()->create(['company_id' => $none->id, 'role' => 'admin']);
        \App\Access\CompatibilityMigration::run();
        $this->actingAs($u, 'sanctum')->getJson($this->url('', $none))->assertStatus(403);
    }

    public function test_a_company_never_sees_nor_acts_on_another_companys_signals(): void
    {
        $this->relevant($this->topic('Feira do queijo'));
        $this->fit();
        $signal = RadarSignal::firstOrFail();
        $this->actingAs($this->otherAdmin, 'sanctum')->getJson($this->url('', $this->other))->assertOk()->assertJsonCount(0, 'data.signals');
        $this->actingAs($this->otherAdmin, 'sanctum')->getJson($this->url())->assertStatus(403);
        EditorialMonth::create(['company_id' => $this->other->id, 'year' => 2026, 'month' => 10, 'state' => EditorialMonth::OPEN]);
        $this->actingAs($this->otherAdmin, 'sanctum')->postJson($this->url('/ignore', $this->other), ['signal_id' => $signal->id])->assertStatus(422);
        $this->assertSame(RadarSignal::NEW, $signal->fresh()->status);
    }

    public function test_react_creates_an_idea_with_the_hook_the_sources_and_the_deadline_and_no_text(): void
    {
        $this->relevant($this->topic('Feira do queijo', ['filter_flags' => ['marca_protegida']]));
        $this->fit();
        $signal = RadarSignal::firstOrFail();
        $res = $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/react'), [
            'signal_id' => $signal->id, 'title' => 'Está a subir: Feira do queijo', 'publish_date' => '2026-10-15', 'networks' => ['instagram'], 'format' => EditorialPost::FORMATS[0],
        ])->assertOk();
        $post = EditorialPost::findOrFail($res->json('data.post.id'));
        $this->assertSame(EditorialPost::STAGE_IDEA, $post->stage);
        $this->assertSame('radar', $post->origin);
        $this->assertSame('2026-10-16', $post->valid_until->toDateString());
        $this->assertStringContainsString('Está a subir: Feira do queijo.', $post->brief);
        $this->assertStringContainsString('Marca protegida: não use os nomes nem os logótipos oficiais.', $post->brief);
        $this->assertSame('Google Trends', $post->sources[0]['source_name']);
        $this->assertSame(0, DB::table('editorial_post_versions')->where('editorial_post_id', $post->id)->count(), '"Reagir" nunca escreve o texto');
        $this->assertSame(RadarSignal::REACTED, $signal->fresh()->status);
        $this->assertSame($post->id, $signal->fresh()->editorial_post_id);

        $detail = $this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/companies/{$this->company->id}/editorial/posts/{$post->id}")->json('data.post');
        if ($detail !== null) {
            $this->assertSame('radar', $detail['origin']);
        }
    }

    public function test_react_refuses_a_closed_month_and_a_past_date(): void
    {
        $this->relevant($this->topic('Feira do queijo', ['window_until' => now()->addDays(40)]));
        $this->fit();
        $signal = RadarSignal::firstOrFail();
        $body = ['signal_id' => $signal->id, 'title' => 'Ideia', 'networks' => ['instagram'], 'format' => EditorialPost::FORMATS[0]];
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/react'), $body + ['publish_date' => '2026-11-03'])->assertStatus(422)
            ->assertJsonPath('errors.publish_date.0', 'O mês de novembro de 2026 não está aberto na Linha Editorial. Abra-o lá primeiro.');
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/react'), $body + ['publish_date' => '2026-10-13'])->assertStatus(422);
        $this->assertSame(RadarSignal::NEW, $signal->fresh()->status);
    }

    public function test_ignore_and_mute_and_unmute(): void
    {
        $this->relevant($this->topic('Feira do queijo'), theme: 'feiras');
        $this->relevant($this->topic('Concurso de vinhos'), theme: 'vinhos');
        $this->fit();
        [$a, $b] = RadarSignal::orderBy('id')->get()->all();
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/ignore'), ['signal_id' => $a->id])->assertOk();
        $this->assertSame(RadarSignal::IGNORED, $a->fresh()->status);
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/mute'), ['signal_id' => $b->id])->assertOk();
        $this->assertSame(RadarSignal::MUTED, $b->fresh()->status);
        $muted = RadarSetting::forCompany($this->company->id)['muted'];
        $this->assertSame('vinhos', $muted[0]['theme']);
        $this->assertSame('concurso de vinhos', $muted[0]['term']);
        $this->actingAs($this->admin, 'sanctum')->getJson($this->url())->assertJsonCount(0, 'data.signals')->assertJsonPath('data.settings.muted.0.title', 'Concurso de vinhos');

        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/unmute'), ['index' => 0])->assertOk()->assertJsonCount(0, 'data.muted');
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/unmute'), ['index' => 0])->assertStatus(422);
    }

    public function test_settings_are_validated_and_written_only_with_bussola_editar(): void
    {
        $this->actingAs($this->admin, 'sanctum')->putJson($this->url('/settings'), ['sports_enabled' => true, 'sports_clubs' => ['Clube Azul', 'Clube Azul']])
            ->assertOk()->assertJsonPath('data.sports_enabled', true)->assertJsonPath('data.sports_clubs', ['Clube Azul']);
        $this->actingAs($this->admin, 'sanctum')->putJson($this->url('/settings'), ['sports_enabled' => true, 'sports_clubs' => array_fill(0, 21, 'Clube')])->assertStatus(422);
        $this->actingAs($this->admin, 'sanctum')->putJson($this->url('/settings'), ['sports_enabled' => true, 'sports_clubs' => [str_repeat('x', 41)]])->assertStatus(422);

        $viewer = \App\Models\PermissionProfile::create(['company_id' => $this->company->id, 'side' => 'cliente', 'name' => 'Só ver', 'is_system' => false, 'is_suggestion' => false]);
        $viewer->syncPermissions(['bussola.ver']);
        $u = User::factory()->create(['company_id' => $this->company->id, 'role' => 'user']);
        $u->forceFill(['profile_id' => $viewer->id])->save();
        $this->actingAs($u, 'sanctum')->getJson($this->url('/settings'))->assertOk();
        $this->actingAs($u, 'sanctum')->putJson($this->url('/settings'), ['sports_enabled' => false])->assertStatus(403);
    }

    public function test_the_root_panel_lists_relevance_and_signals_and_switches_the_module(): void
    {
        $root = User::factory()->create(['company_id' => $this->other->id, 'role' => 'root']);
        $t = $this->topic('Feira do queijo');
        $this->relevant($t);
        $this->fit();
        $summary = $this->actingAs($root, 'sanctum')->getJson('/api/v1/admin/radar')->assertOk()->json('data');
        $this->assertSame('restauracao', $summary['relevance'][0]['sector']);
        $this->assertSame(1, $summary['signals'][0]['today']);
        $this->assertTrue(collect($summary['companies'])->firstWhere('id', $this->company->id)['radar_enabled']);
        $this->actingAs($root, 'sanctum')->getJson('/api/v1/admin/radar/relevances?days=1')->assertOk()->assertJsonPath('data.rows.0.topic', 'Feira do queijo');

        $this->actingAs($root, 'sanctum')->postJson("/api/v1/admin/radar/companies/{$this->company->id}/switch", ['enabled' => false])->assertOk();
        $this->assertFalse(app(CompanyModuleService::class)->isEnabled($this->company->id, 'radar'));
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/admin/radar/companies/{$this->company->id}/switch", ['enabled' => true])->assertStatus(403);
    }
}
