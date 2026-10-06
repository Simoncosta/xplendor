<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiRequest;
use App\Models\Blog;
use App\Models\Company;
use App\Models\CompanyBrandProfile;
use App\Models\CompanyModule;
use App\Models\ContentSector;
use App\Models\EditorialMonth;
use App\Models\EditorialOwnAnchor;
use App\Models\EditorialPost;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Linha Editorial, "Gerar ideias do mês" e ponte para o Blog (OpenAI sempre simulada):
 * só em mês aberto; contexto (âncoras com gancho, próprias, pilares, perfil, publicações do
 * mês); ideias repetidas descartadas; aceitação ideia a ideia (data e canal); nada criado
 * sozinho; limite mensal próprio; tenancy; "Escrever artigo" liga o artigo à publicação.
 */
class EditorialIdeasTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;
    private Company $b;
    private User $userA;
    private User $adminB;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-10 10:00:00', 'Europe/Lisbon'));
        config(['services.openai.key' => 'test-key', 'services.openai.ai_monthly_caps.ideas' => 10]);

        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $sector = ContentSector::where('slug', 'restauracao')->firstOrFail();
        $this->a = Company::create(['nipc' => '500050001', 'fiscal_name' => 'Tasca Lda', 'trade_name' => 'Tasca', 'plan_id' => $planId, 'subscription_status' => 'active', 'content_sector_id' => $sector->id]);
        $this->b = Company::create(['nipc' => '500050002', 'fiscal_name' => 'Outra Lda', 'plan_id' => $planId, 'subscription_status' => 'active', 'content_sector_id' => $sector->id]);
        foreach ([$this->a, $this->b] as $c) {
            CompanyModule::create(['company_id' => $c->id, 'module_key' => 'linha_editorial']);
            EditorialMonth::create(['company_id' => $c->id, 'year' => 2026, 'month' => 12, 'state' => EditorialMonth::OPEN]);
            // O mínimo do Perfil da Marca para gerar ideias.
            CompanyBrandProfile::create(['company_id' => $c->id, 'tone_of_voice' => 'Próximo', 'audience' => 'Famílias do bairro', 'pillars' => [['name' => 'Produtos da época', 'description' => 'x']]]);
        }
        $this->userA = User::factory()->create(['company_id' => $this->a->id, 'role' => 'user']);
        $this->adminB = User::factory()->create(['company_id' => $this->b->id, 'role' => 'admin']);
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

    private function fakeIdeas(?array $ideas = null): void
    {
        $ideas ??= [
            ['title' => 'Menu de Passagem de Ano em preço fechado', 'channel' => 'instagram', 'content_type' => 'Carrossel', 'media_format' => 'ig_carousel', 'date' => '2026-12-15', 'anchor' => 'Passagem de Ano', 'pillar' => 'Produtos da época', 'keyword' => 'reveillon', 'why' => 'Âncora Passagem de Ano e pilar Produtos da época.'],
            ['title' => 'Bastidores da cozinha no Natal', 'channel' => 'facebook', 'content_type' => 'Bastidores', 'media_format' => 'ig_reel', 'date' => '2026-12-40', 'anchor' => 'Natal', 'pillar' => null, 'keyword' => 'natal', 'why' => 'Âncora Natal.'],
            ['title' => 'Como escolher o vinho para a consoada', 'channel' => 'site', 'content_type' => 'Tutorial', 'media_format' => 'ig_feed_image', 'date' => '2026-12-18', 'anchor' => null, 'pillar' => 'Saber à mesa', 'keyword' => 'vinho consoada', 'why' => 'Pilar Saber à mesa.'],
            ['title' => 'Menu de Natal!', 'channel' => 'instagram', 'content_type' => 'Imagem única', 'date' => '2026-12-20', 'why' => 'Repetida.'],
            ['title' => 'Ideia num canal inventado', 'channel' => 'tiktok', 'date' => '2026-12-21', 'why' => 'Inválida.'],
            ['title' => 'Jantar do aniversário da casa', 'channel' => 'instagram', 'content_type' => 'Inventado', 'media_format' => null, 'date' => '2026-11-02', 'anchor' => 'Aniversário da casa', 'why' => 'Âncora própria.'],
        ];
        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode(['ideas' => $ideas])]]],
            'usage' => ['prompt_tokens' => 1500, 'completion_tokens' => 900, 'total_tokens' => 2400],
        ])]);
    }

    private function sentPrompt(): string
    {
        $prompt = '';
        Http::assertSent(function (HttpRequest $r) use (&$prompt) {
            $prompt = (string) ($r['messages'][1]['content'] ?? '');

            return true;
        });

        return $prompt;
    }

    private function generate(Company $c, User $u, int $month = 12, int $year = 2026)
    {
        return $this->as($u)->postJson($this->url($c, '/editorial/ideas'), ['year' => $year, 'month' => $month]);
    }

    private function sitePost(Company $c, string $title = 'Artigo de Natal'): EditorialPost
    {
        return EditorialPost::create(['company_id' => $c->id, 'publish_date' => '2026-12-22', 'title' => $title, 'format' => 'Artigo',
            'status' => 'rascunho', 'channel' => 'site', 'keyword' => 'consoada']);
    }

    // ── Gerar ────────────────────────────────────────────────────────────────

    public function test_ideas_only_for_an_open_month(): void
    {
        $this->fakeIdeas();

        $this->generate($this->a, $this->userA, 1, 2027)->assertStatus(422)->assertJsonPath('message', 'Só pode gerar ideias para um mês aberto.');
        $this->generate($this->a, $this->userA, 11, 2026)->assertStatus(422);
        Http::assertNothingSent();
        $this->assertSame(0, AiRequest::count());
    }

    public function test_generates_ideas_from_anchors_profile_and_existing_posts_without_creating_anything(): void
    {
        $this->fakeIdeas();
        EditorialOwnAnchor::create(['company_id' => $this->a->id, 'title' => 'Aniversário da casa', 'rule_type' => 'fixa', 'month' => 12, 'day' => 12, 'suggestion' => 'jantar com o chefe']);
        CompanyBrandProfile::where('company_id', $this->a->id)->sole()->update(['tone_of_voice' => 'Caloroso', 'pillars' => [['name' => 'Produtos da época', 'description' => 'x'], ['name' => 'Saber à mesa', 'description' => 'y']]]);
        EditorialPost::create(['company_id' => $this->a->id, 'publish_date' => '2026-12-24', 'title' => 'Menu de Natal', 'format' => 'Imagem única', 'status' => 'rascunho', 'channel' => 'instagram']);

        $res = $this->generate($this->a, $this->userA)->assertStatus(202)->assertJsonPath('data.status', 'done');
        $ideas = collect($res->json('data.result.ideas'))->keyBy('title');

        $this->assertCount(4, $ideas, 'Repetida e canal inválido ficam de fora.');
        $this->assertSame(1, $res->json('data.result.skipped_duplicates'));
        $this->assertTrue($res->json('data.result.has_profile'));

        $reveillon = $ideas['Menu de Passagem de Ano em preço fechado'];
        $this->assertSame(['instagram', 'Carrossel', 'ig_carousel', '2026-12-15', 'Passagem de Ano', 'Produtos da época'],
            [$reveillon['channel'], $reveillon['content_type'], $reveillon['media_format'], $reveillon['date'], $reveillon['anchor_title'], $reveillon['pillar']]);
        $this->assertNotNull($reveillon['anchor_id']);

        // Data inválida: passa para a da âncora. Formato de outra rede: fica sem formato (sem regra).
        $natal = $ideas['Bastidores da cozinha no Natal'];
        $this->assertSame(['2026-12-25', null], [$natal['date'], $natal['media_format']]);
        // Site: tipo "Artigo" e sem formato.
        $site = $ideas['Como escolher o vinho para a consoada'];
        $this->assertSame(['Artigo', null, 'Saber à mesa'], [$site['content_type'], $site['media_format'], $site['pillar']]);
        // Âncora própria, tipo inventado e data fora do mês.
        $own = $ideas['Jantar do aniversário da casa'];
        $this->assertSame(['Dica de expert', '2026-12-12'], [$own['content_type'], $own['date']]);
        $this->assertNotNull($own['own_anchor_id']);
        $this->assertNull($own['anchor_id']);

        $prompt = $this->sentPrompt();
        foreach (['Passagem de Ano', 'menu de reveillon, preço fechado, limite de vagas', 'Aniversário da casa', 'jantar com o chefe', 'Menu de Natal', 'Produtos da época', 'Caloroso', '2026-12-10'] as $expected) {
            $this->assertStringContainsString($expected, $prompt, $expected);
        }

        $req = AiRequest::where('mode', AiRequest::MODE_IDEAS)->sole();
        $this->assertSame(['ideas-v1', 2400], [$req->prompt_version, $req->total_tokens]);
        $this->assertSame(1, EditorialPost::where('company_id', $this->a->id)->count(), 'Nada é criado sozinho.');
    }

    public function test_refuses_without_the_minimum_profile_and_accepts_with_it(): void
    {
        $this->fakeIdeas();
        $profile = CompanyBrandProfile::where('company_id', $this->a->id)->sole();
        $profile->delete();

        $all = 'Para gerar ideias, preencha no Perfil da Marca o tom de voz, o público e pelo menos um pilar.';
        $this->generate($this->a, $this->userA)->assertStatus(422)->assertJsonPath('message', $all);
        $this->as($this->userA)->getJson($this->url($this->a, '/brand-profile'))
            ->assertOk()->assertJsonPath('data.ideas_ready', false)->assertJsonPath('data.ideas_blocked_reason', $all);

        // Pilar sem nome não conta.
        CompanyBrandProfile::create(['company_id' => $this->a->id, 'tone_of_voice' => 'Caloroso', 'audience' => '  ', 'pillars' => [['name' => ' ', 'description' => 'x']]]);
        $this->generate($this->a, $this->userA)->assertStatus(422)
            ->assertJsonPath('message', 'Para gerar ideias, preencha no Perfil da Marca o público e pelo menos um pilar.');
        Http::assertNothingSent();
        $this->assertSame(0, AiRequest::count());

        // Com o mínimo, gera; o ramo deixou de ser condição.
        CompanyBrandProfile::where('company_id', $this->a->id)->sole()->update(['audience' => 'Famílias', 'pillars' => [['name' => 'Saber à mesa', 'description' => '']]]);
        $this->a->update(['content_sector_id' => null]);
        $this->as($this->userA)->getJson($this->url($this->a, '/brand-profile'))
            ->assertOk()->assertJsonPath('data.ideas_ready', true)->assertJsonPath('data.ideas_blocked_reason', null);
        $this->generate($this->a, $this->userA)->assertStatus(202)->assertJsonPath('data.status', 'done')->assertJsonPath('data.result.has_profile', true);

        // Mantém-se: no modo "Produção pela equipa", o cliente gerido não gera ideias.
        $this->a->forceFill(['content_production_mode' => 'team'])->save();
        $this->generate($this->a, $this->userA)->assertStatus(403);
        $this->assertSame(1, AiRequest::count());
    }

    // ── Aceitar ──────────────────────────────────────────────────────────────

    public function test_accepting_idea_by_idea_creates_drafts_with_optional_date_and_channel(): void
    {
        $this->fakeIdeas();
        $res = $this->generate($this->a, $this->userA)->assertStatus(202);
        $id = $res->json('data.id');
        $ideas = $res->json('data.result.ideas');
        $idx = fn (string $title) => collect($ideas)->search(fn ($i) => $i['title'] === $title);
        $accept = fn (array $body) => $this->as($this->userA)->postJson($this->url($this->a, "/editorial/ideas/{$id}/accept"), $body);

        // 1. Como veio, mas noutra data.
        $r = $accept(['index' => $idx('Menu de Passagem de Ano em preço fechado'), 'publish_date' => '2026-12-14'])->assertOk();
        $post = EditorialPost::findOrFail($r->json('data.post_id'));
        $this->assertSame(['rascunho', 'instagram', '2026-12-14', 'ig_carousel', 'Carrossel'], [$post->status, $post->channel, $post->publish_date->toDateString(), $post->media_format, $post->format]);
        $this->assertNotNull($post->anchor_id);
        $this->assertSame($post->id, collect($r->json('data.ideas.result.ideas'))->firstWhere('title', 'Menu de Passagem de Ano em preço fechado')['accepted_post_id']);

        // 2. A mesma ideia outra vez: recusada.
        $accept(['index' => $idx('Menu de Passagem de Ano em preço fechado')])->assertStatus(409);

        // 3. Site → Instagram: tipo por omissão; Instagram → Site: "Artigo" e sem formato.
        $toIg = EditorialPost::findOrFail($accept(['index' => $idx('Como escolher o vinho para a consoada'), 'channel' => 'instagram'])->assertOk()->json('data.post_id'));
        $this->assertSame(['instagram', 'Dica de expert'], [$toIg->channel, $toIg->format]);
        $toSite = EditorialPost::findOrFail($accept(['index' => $idx('Bastidores da cozinha no Natal'), 'channel' => 'site'])->assertOk()->json('data.post_id'));
        $this->assertSame(['site', 'Artigo', null], [$toSite->channel, $toSite->format, $toSite->media_format]);

        // 4. Data num mês fechado: recusada pelas regras de sempre.
        $accept(['index' => $idx('Jantar do aniversário da casa'), 'publish_date' => '2027-01-10'])->assertStatus(422);

        // 5. Sem duplicar: se, entretanto, já existir uma publicação com o mesmo título no mês.
        EditorialPost::create(['company_id' => $this->a->id, 'publish_date' => '2026-12-28', 'title' => 'Jantar do aniversário da casa', 'format' => 'Citação', 'status' => 'rascunho', 'channel' => 'facebook']);
        $accept(['index' => $idx('Jantar do aniversário da casa')])->assertStatus(422)->assertJsonPath('message', 'Já existe uma publicação com este título nesse mês.');

        $this->assertSame(4, EditorialPost::where('company_id', $this->a->id)->count(), 'Só as aceites (e a criada à mão) existem.');
    }

    // ── Limite e tenancy ─────────────────────────────────────────────────────

    public function test_monthly_limit_is_specific_to_ideas(): void
    {
        $this->fakeIdeas();
        $row = fn (string $mode, string $status, $when) => DB::table('ai_requests')->insert([
            'company_id' => $this->a->id, 'mode' => $mode, 'status' => $status, 'input' => '{}', 'model' => 'gpt-4o', 'prompt_version' => 'x',
            'created_at' => $when, 'updated_at' => $when,
        ]);
        for ($i = 0; $i < 9; $i++) {
            $row('ideas', 'done', now());
        }
        $row('ideas', 'error', now());                                 // falhas não contam
        $row('ideas', 'done', now()->subMonthNoOverflow());            // mês anterior não conta
        $row('creative', 'done', now());                               // outro modo não conta

        $this->generate($this->a, $this->userA)->assertStatus(202)->assertJsonPath('data.used', 10)->assertJsonPath('data.cap', 10);
        $this->generate($this->a, $this->userA)->assertStatus(429);
        $this->generate($this->b, $this->adminB)->assertStatus(202);
    }

    public function test_tenancy(): void
    {
        $this->fakeIdeas();
        $idB = $this->generate($this->b, $this->adminB)->assertStatus(202)->json('data.id');

        $this->generate($this->b, $this->userA)->assertStatus(403);
        $this->as($this->userA)->getJson($this->url($this->b, "/editorial/ideas/{$idB}"))->assertStatus(403);
        $this->as($this->userA)->getJson($this->url($this->a, "/editorial/ideas/{$idB}"))->assertStatus(404);
        $this->as($this->userA)->postJson($this->url($this->a, "/editorial/ideas/{$idB}/accept"), ['index' => 0])->assertStatus(404);
        $this->assertSame(0, EditorialPost::count());
    }

    // ── Ponte para o Blog ────────────────────────────────────────────────────

    public function test_write_article_links_the_new_article_to_the_site_post(): void
    {
        $post = $this->sitePost($this->a);

        $blog = $this->as($this->userA)->postJson($this->url($this->a, '/blogs'), [
            'title' => 'Artigo de Natal', 'focus_keyword' => 'consoada', 'editorial_post_id' => $post->id,
        ])->assertStatus(201)->json('data');

        $this->assertSame($blog['id'], $post->fresh()->blog_id);
        $this->assertSame(['id' => $post->id, 'title' => 'Artigo de Natal', 'publish_date' => '2026-12-22'], $blog['editorial_post']);
        $calendarPost = collect($this->as($this->userA)->getJson($this->url($this->a, '/editorial/calendar'))->json('data.posts'))->firstWhere('id', $post->id);
        $this->assertSame($blog['id'], $calendarPost['blog']['id']);

        // Um segundo artigo para a mesma publicação é recusado (e não fica criado).
        $this->as($this->userA)->postJson($this->url($this->a, '/blogs'), ['title' => 'Outro', 'editorial_post_id' => $post->id])
            ->assertStatus(422)->assertJsonValidationErrors('editorial_post_id');
        $this->assertSame(1, Blog::where('company_id', $this->a->id)->count());
    }

    public function test_write_article_only_for_site_posts_of_the_same_company(): void
    {
        $instagram = EditorialPost::create(['company_id' => $this->a->id, 'publish_date' => '2026-12-22', 'title' => 'Reel', 'format' => 'Reels', 'status' => 'rascunho', 'channel' => 'instagram']);
        $foreign = $this->sitePost($this->b);

        $this->as($this->userA)->postJson($this->url($this->a, '/blogs'), ['title' => 'X', 'editorial_post_id' => $instagram->id])->assertStatus(422);
        $this->as($this->userA)->postJson($this->url($this->a, '/blogs'), ['title' => 'X', 'editorial_post_id' => $foreign->id])->assertStatus(422);
        $this->assertNull($foreign->fresh()->blog_id);
        $this->assertSame(0, Blog::count());
    }
}
