<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiRequest;
use App\Models\Company;
use App\Models\CompanyBrandProfile;
use App\Models\CompanyModule;
use App\Models\ContentSector;
use App\Models\CreativeFormatRule;
use App\Models\EditorialMonth;
use App\Models\EditorialPost;
use App\Models\EditorialPostCreative;
use App\Models\SocialFollowerSnapshot;
use App\Models\User;
use App\Services\Blog\AudienceSummaryService;
use App\Services\Brand\BrandProfileTemplates;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Perfil da Marca, Parte B, sempre com a OpenAI SIMULADA: "Sugerir perfil" (modelos por
 * ramo, proposta campo a campo, nunca grava), "Sugerir criativo" (formato pela regra de
 * referência com a fonte, aceitação campo a campo), regras de formato só do root, o campo
 * "formato" separado do "tipo de conteúdo", limites por modo e tenancy.
 */
class BrandProfileAssistantsTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\FakesAi;

    private Company $a;
    private Company $b;
    private User $adminA;
    private User $userA;
    private User $adminB;
    private User $root;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-05 10:00:00', 'Europe/Lisbon'));
        $this->configureAi();
        config([
            'services.openai.ai_monthly_caps' => ['blog' => 30, 'brand_profile' => 10, 'creative' => 60],
        ]);

        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $restauracao = ContentSector::where('slug', 'restauracao')->first();
        $this->a = Company::create(['nipc' => '500040001', 'fiscal_name' => 'Quebom Lda', 'trade_name' => 'Quebom', 'plan_id' => $planId,
            'subscription_status' => 'active', 'content_sector_id' => $restauracao?->id, 'instagram' => '@quebom']);
        $this->b = Company::create(['nipc' => '500040002', 'fiscal_name' => 'Outra Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $x = Company::create(['nipc' => '500040003', 'fiscal_name' => 'XPLENDOR', 'plan_id' => $planId, 'subscription_status' => 'active']);
        foreach ([$this->a, $this->b] as $c) {
            CompanyModule::create(['company_id' => $c->id, 'module_key' => 'linha_editorial']);
            EditorialMonth::create(['company_id' => $c->id, 'year' => 2026, 'month' => 10, 'state' => EditorialMonth::OPEN]);
        }
        $this->adminA = User::factory()->create(['company_id' => $this->a->id, 'role' => 'admin']);
        $this->userA = User::factory()->create(['company_id' => $this->a->id, 'role' => 'user']);
        $this->adminB = User::factory()->create(['company_id' => $this->b->id, 'role' => 'admin']);
        $this->root = User::factory()->create(['company_id' => $x->id, 'role' => 'root']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function url(Company $c, string $suffix): string
    {
        return "/api/v1/companies/{$c->id}{$suffix}";
    }

    private function as(User $u): self
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u, 'sanctum');
    }

    private function fakeOpenAi(array $content, int $status = 200): void
    {
        Http::fake(['api.anthropic.com/*' => $status === 200
            ? $this->anthropicResponse($content, ['input_tokens' => 500, 'output_tokens' => 300])
            : Http::response(['type' => 'error', 'error' => ['message' => 'invalid']], $status)]);
    }

    private function sentUserPrompt(): string
    {
        return $this->sentAiPrompt(false);
    }

    private function makePost(Company $c, string $channel = 'instagram', array $attrs = []): EditorialPost
    {
        return EditorialPost::create(array_merge([
            'company_id' => $c->id, 'publish_date' => '2026-10-20', 'title' => 'Menu de outono', 'format' => 'Bastidores',
            'status' => 'rascunho', 'channel' => $channel, 'keyword' => 'outono',
        ], $attrs));
    }

    private function followers(Company $c, string $platform, int $count): void
    {
        SocialFollowerSnapshot::create(['company_id' => $c->id, 'platform' => $platform, 'snapshot_date' => '2026-10-04', 'followers_count' => $count, 'source' => 'manual']);
    }

    // ── Modelos por ramo ─────────────────────────────────────────────────────

    public function test_templates_are_chosen_by_sector_with_universal_fallback(): void
    {
        $leaf = fn (string $slug) => ContentSector::where('slug', $slug)->first();

        $this->assertSame('restauracao', BrandProfileTemplates::keyFor($leaf('restauracao')));
        $this->assertSame('autocaravanas', BrandProfileTemplates::keyFor($leaf('autocaravanas')));
        $this->assertSame('universal', BrandProfileTemplates::keyFor($leaf('automovel')));   // agrupador
        $this->assertSame('universal', BrandProfileTemplates::keyFor(null));
        $this->assertSame('brand-templates-v1', BrandProfileTemplates::for(null)['version']);

        // Nenhum texto dos modelos usa travessões.
        foreach (BrandProfileTemplates::keys() as $key) {
            $this->assertDoesNotMatchRegularExpression('/[–—]/u', json_encode(BrandProfileTemplates::for(ContentSector::where('slug', $key)->first()), JSON_UNESCAPED_UNICODE));
        }
    }

    // ── Sugerir perfil ───────────────────────────────────────────────────────

    public function test_suggest_profile_proposes_field_by_field_and_never_saves(): void
    {
        CompanyBrandProfile::create(['company_id' => $this->a->id, 'tone_of_voice' => 'Tom atual da casa', 'words_to_use' => ['sabor']]);
        $this->fakeOpenAi(['fields' => [
            'tone_of_voice'    => ['value' => 'Acolhedor <b>e</b> próximo', 'reason' => 'Modelo do ramo', 'source' => 'template'],
            'hashtags_default' => ['value' => ['comida boa', '#Porto', '#porto'], 'reason' => 'Zona', 'source' => 'company_data'],
            'emoji_policy'     => ['value' => 'muitos', 'reason' => 'x', 'source' => 'template'],
            'pillars'          => ['value' => [['name' => 'Bastidores', 'description' => 'A cozinha'], ['description' => 'sem nome']], 'reason' => 'r', 'source' => 'inventado'],
            'campo_estranho'   => ['value' => 'x', 'reason' => 'x', 'source' => 'template'],
        ]]);

        $id = $this->as($this->adminA)->postJson($this->url($this->a, '/brand-profile/suggestions'))->assertStatus(202)->json('data.id');
        $data = $this->as($this->adminA)->getJson($this->url($this->a, "/brand-profile/suggestions/{$id}"))->assertOk()->json('data');

        $this->assertSame('done', $data['status']);
        $this->assertSame('restauracao', $data['template']);
        $fields = $data['result']['fields'];
        $this->assertSame(['tone_of_voice', 'hashtags_default', 'pillars'], array_keys($fields));
        $this->assertSame('Acolhedor e próximo', $fields['tone_of_voice']['value']);
        $this->assertSame(['#comidaboa', '#Porto', '#porto'], $fields['hashtags_default']['value']);
        $this->assertSame([['name' => 'Bastidores', 'description' => 'A cozinha']], $fields['pillars']['value']);
        $this->assertSame('template', $fields['pillars']['source']);   // fonte inválida → modelo
        $this->assertSame(['restauracao', 'brand-templates-v1'], [$data['result']['template'], $data['result']['template_version']]);

        // Nunca grava o perfil.
        $profile = CompanyBrandProfile::where('company_id', $this->a->id)->first();
        $this->assertSame('Tom atual da casa', $profile->tone_of_voice);
        $this->assertNull($profile->hashtags_default);

        $row = AiRequest::find($id);
        $this->assertSame(['brand_profile', 'brand-profile-v1', 800], [$row->mode, $row->prompt_version, $row->total_tokens]);

        $prompt = $this->sentUserPrompt();
        foreach (['Restauração', 'brand-templates-v1', 'Quebom', '@quebom', 'Tom atual da casa', AudienceSummaryService::NO_DATA_WARNING, '<<<DADOS'] as $expected) {
            $this->assertStringContainsString($expected, $prompt, $expected);
        }
    }

    public function test_suggest_profile_permissions_tenancy_and_its_own_cap(): void
    {
        $this->fakeOpenAi(['fields' => []]);

        $this->as($this->userA)->postJson($this->url($this->a, '/brand-profile/suggestions'))->assertStatus(403);
        $this->as($this->adminB)->postJson($this->url($this->a, '/brand-profile/suggestions'))->assertStatus(403);
        $id = $this->as($this->adminA)->postJson($this->url($this->a, '/brand-profile/suggestions'))->assertStatus(202)->json('data.id');
        $this->as($this->adminB)->getJson($this->url($this->b, "/brand-profile/suggestions/{$id}"))->assertStatus(404);

        // Limite próprio do modo: o blog não o gasta e ele não gasta o do blog.
        config(['services.openai.ai_monthly_caps.brand_profile' => 1]);
        $this->as($this->adminA)->postJson($this->url($this->a, '/brand-profile/suggestions'))->assertStatus(429);
        $this->assertSame(1, AiRequest::where('company_id', $this->a->id)->where('mode', 'brand_profile')->count());
        $this->assertSame(0, AiRequest::where('company_id', $this->a->id)->where('mode', 'blog')->count());
    }

    public function test_suggest_profile_failure_is_recorded_and_counts_only_when_the_provider_answered(): void
    {
        // Sem resposta do fornecedor (ligação falhada nas três tentativas): não conta. Depois, um erro 400: conta.
        Http::fakeSequence('api.anthropic.com/*')->pushFailedConnection()->pushFailedConnection()->pushFailedConnection()
            ->push(['type' => 'error', 'error' => ['message' => 'invalid']], 400);
        $id = $this->as($this->adminA)->postJson($this->url($this->a, '/brand-profile/suggestions'))->assertStatus(202)->json('data.id');
        $this->as($this->adminA)->getJson($this->url($this->a, "/brand-profile/suggestions/{$id}"))->assertOk()
            ->assertJsonPath('data.status', 'error')->assertJsonPath('data.used', 0);

        // Erro com resposta do fornecedor: conta.
        $id = $this->as($this->adminA)->postJson($this->url($this->a, '/brand-profile/suggestions'))->assertStatus(202)->json('data.id');
        $this->as($this->adminA)->getJson($this->url($this->a, "/brand-profile/suggestions/{$id}"))->assertOk()
            ->assertJsonPath('data.status', 'error')->assertJsonPath('data.result', null)->assertJsonPath('data.used', 1);
    }

    // ── Regras de formato (referência de mercado) ────────────────────────────

    public function test_format_rules_are_seeded_from_the_study_with_the_source_placeholder(): void
    {
        $rows = CreativeFormatRule::orderBy('followers_min')->orderBy('rank')->get();
        $this->assertCount(7, $rows);
        $this->assertSame(['[FONTE DO ESTUDO]'], $rows->pluck('source_label')->unique()->values()->all());
        $this->assertSame(
            [[0, 10000, 'ig_carousel', 6.81], [0, 10000, 'ig_reel', 5.47], [0, 10000, 'ig_feed_image', 4.65],
             [10001, 500000, 'ig_reel', null],
             [500001, null, 'ig_reel', 4.94], [500001, null, 'ig_carousel', 3.77], [500001, null, 'ig_feed_image', 2.78]],
            $rows->map(fn ($r) => [$r->followers_min, $r->followers_max, $r->format_key, $r->engagement_rate])->all()
        );
    }

    public function test_only_root_manages_format_rules(): void
    {
        $payload = ['channel' => 'instagram', 'followers_min' => 0, 'followers_max' => 1000, 'format_key' => 'ig_story', 'rank' => 4, 'source_label' => 'Estudo X'];

        $this->as($this->adminA)->getJson('/api/v1/admin/creative-format-rules')->assertStatus(403);
        $this->as($this->adminA)->postJson('/api/v1/admin/creative-format-rules', $payload)->assertStatus(403);

        $id = $this->as($this->root)->postJson('/api/v1/admin/creative-format-rules', $payload)->assertStatus(201)->json('data.id');
        $this->as($this->root)->putJson("/api/v1/admin/creative-format-rules/{$id}", ['engagement_rate' => 3.5] + $payload)->assertOk()
            ->assertJsonPath('data.engagement_rate', 3.5);
        $this->as($this->root)->postJson('/api/v1/admin/creative-format-rules', ['format_key' => 'fb_reel'] + $payload)->assertStatus(422);   // formato de outra rede
        $this->as($this->root)->postJson('/api/v1/admin/creative-format-rules', ['followers_max' => -1, 'followers_min' => 10] + $payload)->assertStatus(422);
        $this->as($this->root)->deleteJson("/api/v1/admin/creative-format-rules/{$id}")->assertOk();
        $this->assertCount(7, CreativeFormatRule::all());
    }

    // ── Sugerir criativo ─────────────────────────────────────────────────────

    public function test_suggest_creative_uses_the_band_rule_with_its_source(): void
    {
        $this->followers($this->a, 'instagram', 4200);
        $post = $this->makePost($this->a);
        $this->fakeOpenAi(['media_format' => 'ig_carousel', 'hook' => 'O outono chegou à mesa', 'caption' => "Linha 1\nLinha 2 <script>x</script>",
            'hashtags' => ['outono', '#Porto'], 'cta' => 'Reserve já', 'why' => 'Carrossel lidera na faixa até 10 mil.']);

        $id = $this->as($this->userA)->postJson($this->url($this->a, "/editorial/posts/{$post->id}/creative-suggestions"))->assertStatus(202)->json('data.id');
        $r = $this->as($this->userA)->getJson($this->url($this->a, "/editorial/posts/{$post->id}/creative-suggestions/{$id}"))->assertOk()->json('data.result');

        $this->assertSame(['ig_carousel', 'market_reference', '[FONTE DO ESTUDO]', 4200], [$r['media_format'], $r['source'], $r['source_label'], $r['followers']]);
        $this->assertSame(['ig_carousel', 'ig_reel', 'ig_feed_image'], array_column($r['ranked'], 'format_key'));
        $this->assertSame("Linha 1\nLinha 2 x", $r['caption']);
        $this->assertSame(['#outono', '#Porto'], $r['hashtags']);

        $prompt = $this->sentUserPrompt();
        foreach (['Menu de outono', 'Bastidores', 'referência de mercado ([FONTE DO ESTUDO])', '6,81%', 'ig_feed_image, ig_carousel, ig_reel, ig_story'] as $expected) {
            $this->assertStringContainsString($expected, $prompt, $expected);
        }
        $this->assertNull($post->fresh()->media_format, 'a sugestão nunca grava na publicação');
        $this->assertSame(0, EditorialPostCreative::count());
    }

    public function test_suggest_creative_falls_back_to_the_rule_and_says_when_there_is_no_reference(): void
    {
        // Formato inválido da IA → o da regra (a faixa acima de 10 mil começa no vídeo).
        $this->followers($this->a, 'instagram', 25000);
        $post = $this->makePost($this->a);
        $answer = fn (string $format) => $this->anthropicResponse(['media_format' => $format, 'hook' => 'h', 'caption' => 'c', 'hashtags' => [], 'cta' => 'x', 'why' => 'y']);
        Http::fakeSequence('api.anthropic.com/*')->pushResponse($answer('fb_reel'))->pushResponse($answer('fb_photos'));
        $id = $this->as($this->userA)->postJson($this->url($this->a, "/editorial/posts/{$post->id}/creative-suggestions"))->json('data.id');
        $r = AiRequest::find($id)->result;
        $this->assertSame('ig_reel', $r['media_format']);
        $this->assertNotNull($r['media_format_note']);

        // Facebook: não há regra → sem referência (e o prompt di-lo).
        $fb = $this->makePost($this->a, 'facebook');
        $id = $this->as($this->userA)->postJson($this->url($this->a, "/editorial/posts/{$fb->id}/creative-suggestions"))->json('data.id');
        $this->assertSame(['none', 'fb_photos'], [AiRequest::find($id)->result['source'], AiRequest::find($id)->result['media_format']]);
        $sent = collect(Http::recorded())->map(fn ($pair) => (string) ($pair[0]['messages'][0]['content'][0]['text'] ?? ''))->last();
        $this->assertStringContainsString('Sem referência: os seguidores atuais desta rede não são conhecidos.', $sent);
    }

    public function test_suggest_creative_tenancy_channel_and_cap(): void
    {
        $this->fakeOpenAi(['media_format' => 'ig_reel']);
        $postB = $this->makePost($this->b);
        $site = $this->makePost($this->a, 'site', ['format' => 'Artigo']);

        $this->as($this->userA)->postJson($this->url($this->a, "/editorial/posts/{$postB->id}/creative-suggestions"))->assertStatus(404);
        $this->as($this->userA)->postJson($this->url($this->b, "/editorial/posts/{$postB->id}/creative-suggestions"))->assertStatus(403);
        $this->as($this->userA)->postJson($this->url($this->a, "/editorial/posts/{$site->id}/creative-suggestions"))->assertStatus(422);

        config(['services.openai.ai_monthly_caps.creative' => 0]);
        $this->as($this->userA)->postJson($this->url($this->a, '/editorial/posts/' . $this->makePost($this->a)->id . '/creative-suggestions'))->assertStatus(429);
    }

    public function test_accepting_a_creative_saves_only_the_chosen_fields(): void
    {
        $this->followers($this->a, 'instagram', 4200);
        $post = $this->makePost($this->a);
        $this->fakeOpenAi(['media_format' => 'ig_carousel', 'hook' => 'Gancho', 'caption' => 'Legenda', 'hashtags' => ['a'], 'cta' => 'CTA', 'why' => 'Porque sim.']);
        $sid = $this->as($this->userA)->postJson($this->url($this->a, "/editorial/posts/{$post->id}/creative-suggestions"))->json('data.id');

        // Escolhe só o formato e o gancho (a legenda não).
        $this->as($this->userA)->putJson($this->url($this->a, "/editorial/posts/{$post->id}/creative"), ['suggestion_id' => $sid, 'media_format' => 'ig_carousel', 'hook' => 'Gancho editado'])
            ->assertOk()->assertJsonPath('data.creative.hook', 'Gancho editado')->assertJsonPath('data.creative.caption', null)
            ->assertJsonPath('data.creative.source_label', '[FONTE DO ESTUDO]')->assertJsonPath('data.creative.rationale', 'Porque sim.')
            ->assertJsonPath('data.media_format', 'ig_carousel');
        $this->assertSame('ig_carousel', $post->fresh()->media_format);

        // Sem campos, formato de outra rede, sugestão de outra publicação, outra empresa.
        $this->as($this->userA)->putJson($this->url($this->a, "/editorial/posts/{$post->id}/creative"), ['suggestion_id' => $sid])->assertStatus(422);
        $this->as($this->userA)->putJson($this->url($this->a, "/editorial/posts/{$post->id}/creative"), ['media_format' => 'fb_reel'])->assertStatus(422);
        $other = $this->makePost($this->a);
        $this->as($this->userA)->putJson($this->url($this->a, "/editorial/posts/{$other->id}/creative"), ['suggestion_id' => $sid, 'hook' => 'x'])->assertStatus(404);
        $this->as($this->adminB)->putJson($this->url($this->a, "/editorial/posts/{$post->id}/creative"), ['hook' => 'x'])->assertStatus(403);

        // Mês fechado: não se aceita.
        EditorialMonth::where('company_id', $this->a->id)->update(['state' => EditorialMonth::CLOSED]);
        $this->as($this->userA)->putJson($this->url($this->a, "/editorial/posts/{$post->id}/creative"), ['hook' => 'x'])->assertStatus(422);
        $this->assertSame('Gancho editado', EditorialPostCreative::where('editorial_post_id', $post->id)->value('hook'));
    }

    // ── Formato separado do tipo de conteúdo ─────────────────────────────────

    public function test_media_format_is_separate_from_content_type_and_validated_per_channel(): void
    {
        $base = ['title' => 'T', 'publish_date' => '2026-10-21', 'format' => 'Tutorial', 'status' => 'rascunho', 'channel' => 'instagram'];

        $this->as($this->userA)->postJson($this->url($this->a, '/editorial/posts'), $base + ['media_format' => 'ig_reel'])->assertOk();
        $post = EditorialPost::where('company_id', $this->a->id)->where('title', 'T')->sole();
        $this->assertSame(['Tutorial', 'ig_reel'], [$post->format, $post->media_format]);

        $this->as($this->userA)->postJson($this->url($this->a, '/editorial/posts'), ['title' => 'U'] + $base + ['media_format' => 'fb_video'])->assertStatus(422);

        // Um ecrã antigo que não envia o formato não o apaga.
        $this->as($this->userA)->putJson($this->url($this->a, "/editorial/posts/{$post->id}"), $base)->assertOk();
        $this->assertSame('ig_reel', $post->fresh()->media_format);

        $calendar = $this->as($this->userA)->getJson($this->url($this->a, '/editorial/calendar'))->assertOk()->json('data.posts');
        $row = collect($calendar)->firstWhere('id', $post->id);
        $this->assertSame(['Tutorial', 'ig_reel', false], [$row['format'], $row['media_format'], $row['has_creative']]);
    }

    public function test_migration_copies_legacy_media_formats_without_deleting_anything(): void
    {
        // Só a migração da Parte B (há migrações mais recentes depois dela). A multicanal
        // (2026_11_23) tira editorial_posts.media_format: desfaz-se primeiro e volta no fim.
        $multichannel = 'database/migrations/2026_11_23_100000_editorial_posts_multichannel.php';
        $this->artisan('migrate:rollback', ['--path' => $multichannel])->assertSuccessful();
        $migration = 'database/migrations/2026_11_14_100000_create_creative_suggestions_foundation.php';
        $this->artisan('migrate:rollback', ['--path' => $migration])->assertSuccessful();

        $ids = [];
        foreach ([['instagram', 'Carrossel'], ['instagram', 'Vídeo'], ['facebook', 'Imagem única'], ['facebook', 'Stories'], ['instagram', 'Tutorial'], ['site', 'Artigo']] as [$channel, $format]) {
            $ids[] = DB::table('editorial_posts')->insertGetId(['company_id' => $this->a->id, 'publish_date' => '2026-10-10', 'title' => $format,
                'format' => $format, 'status' => 'rascunho', 'channel' => $channel, 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->artisan('migrate', ['--path' => $migration])->assertSuccessful();

        $rows = DB::table('editorial_posts')->whereIn('id', $ids)->orderBy('id')->get(['format', 'media_format']);
        $this->assertSame(
            [['Carrossel', 'ig_carousel'], ['Vídeo', 'ig_reel'], ['Imagem única', 'fb_photos'], ['Stories', 'fb_story'], ['Tutorial', null], ['Artigo', null]],
            $rows->map(fn ($r) => [$r->format, $r->media_format])->all()
        );

        // A multicanal converte o canal único na lista de redes, com o formato de cada uma.
        $this->artisan('migrate', ['--path' => $multichannel])->assertSuccessful();
        $this->assertSame(['social', 'social', 'social', 'social', 'social', 'site'], DB::table('editorial_posts')->whereIn('id', $ids)->orderBy('id')->pluck('channel')->all());
        $this->assertSame([[$ids[0], 'instagram', 'ig_carousel'], [$ids[1], 'instagram', 'ig_reel'], [$ids[2], 'facebook', 'fb_photos'], [$ids[3], 'facebook', 'fb_story'], [$ids[4], 'instagram', null]],
            DB::table('editorial_post_networks')->whereIn('editorial_post_id', $ids)->orderBy('editorial_post_id')->get()->map(fn ($n) => [$n->editorial_post_id, $n->network, $n->media_format])->all());
    }
}
