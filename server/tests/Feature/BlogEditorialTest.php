<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\PublishScheduledBlogsJob;
use App\Mail\BlogReviewRequestedMail;
use App\Models\Alert;
use App\Models\Blog;
use App\Models\Company;
use App\Models\ImpersonationSession;
use App\Models\User;
use App\Services\BlogWorkflowService;
use App\Support\BlogHtml;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Blog (Ticket 11): edição, slug único por empresa e fixo depois de publicado, limpeza do
 * HTML (ao gravar e ao mostrar), listagem do root, tenancy, fluxo de aprovação (admin vs
 * utilizador vs equipa XPLENDOR vs impersonation), "[VERIFICAR]", agendamento e avisos.
 */
class BlogEditorialTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;
    private Company $b;
    private Company $x;
    private User $adminA;
    private User $userA;
    private User $adminB;
    private User $root;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('public');

        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->a = Company::create(['nipc' => '500019001', 'fiscal_name' => 'Quebom Lda', 'trade_name' => 'Quebom', 'plan_id' => $planId, 'subscription_status' => 'active', 'public_api_token' => 'tok-a', 'website' => 'https://quebom.pt']);
        $this->b = Company::create(['nipc' => '500019002', 'fiscal_name' => 'Outra Lda', 'plan_id' => $planId, 'subscription_status' => 'active', 'public_api_token' => 'tok-b']);
        $this->x = Company::create(['nipc' => '500019003', 'fiscal_name' => 'XPLENDOR', 'plan_id' => $planId, 'subscription_status' => 'active']);

        $this->adminA = User::factory()->create(['company_id' => $this->a->id, 'role' => 'admin', 'email' => 'admin@quebom.pt']);
        $this->userA = User::factory()->create(['company_id' => $this->a->id, 'role' => 'user', 'email' => 'user@quebom.pt']);
        $this->adminB = User::factory()->create(['company_id' => $this->b->id, 'role' => 'admin', 'email' => 'admin@outra.pt']);
        $this->root = User::factory()->create(['company_id' => $this->x->id, 'role' => 'root', 'email' => 'equipa@xplendor.tech']);
    }

    private function url(Company $c, string $suffix = ''): string
    {
        return "/api/v1/companies/{$c->id}/blogs{$suffix}";
    }

    private function as(User $u): self
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u, 'sanctum');
    }

    /** Token de impersonation do root sobre um utilizador (o token é do alvo). */
    private function impersonating(User $target): self
    {
        $this->app['auth']->forgetGuards();
        $nt = $target->createToken('impersonation', ['impersonation'], now()->addMinutes(30));
        ImpersonationSession::create(['root_id' => $this->root->id, 'target_user_id' => $target->id, 'company_id' => $target->company_id,
            'token_id' => $nt->accessToken->getKey(), 'ip' => '127.0.0.1', 'user_agent' => 'test', 'started_at' => now()]);

        return $this->withHeaders(['Authorization' => 'Bearer ' . $nt->plainTextToken, 'Accept' => 'application/json']);
    }

    private function words(int $n): string
    {
        return '<p>' . trim(str_repeat('ação ', $n)) . '</p>';
    }

    private function create(User $u, Company $c, array $extra = []): array
    {
        return $this->as($u)->postJson($this->url($c), array_merge(['title' => 'Dicas de inverno', 'content' => '<h2>Antes</h2>' . $this->words(50)], $extra))
            ->assertStatus(201)->json('data');
    }

    /** Insere sem passar pelo serviço (slug, estado e conteúdo exatamente como indicados). */
    private function raw(Company $c, array $extra = []): int
    {
        return DB::table('blogs')->insertGetId(array_merge([
            'company_id' => $c->id, 'user_id' => User::factory()->create(['company_id' => $c->id])->id, 'title' => 'Artigo',
            'slug' => 'artigo-' . uniqid(), 'content' => '<p>Texto</p>', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    // ── Edição ───────────────────────────────────────────────────────────────

    public function test_writer_creates_a_draft_and_edits_it_without_resending_the_banner(): void
    {
        $blog = $this->as($this->userA)->post($this->url($this->a), [
            'title' => 'Dicas de inverno', 'content' => $this->words(20), 'status' => 'published',
            'banner' => UploadedFile::fake()->image('capa.jpg', 1200, 630),
        ], ['Accept' => 'application/json'])->assertStatus(201)->json('data');

        $this->assertSame('draft', $blog['status'], 'o estado nunca vem do formulário');
        $this->assertSame('dicas-de-inverno', $blog['slug']);
        $this->assertStringStartsWith("/storage/company_{$this->a->id}/blogs/dicas-de-inverno-", $blog['banner']);

        // Edição com o mesmo slug e o banner atual em texto (o que o formulário antigo enviava).
        $this->as($this->userA)->post($this->url($this->a, "/{$blog['id']}"), [
            '_method' => 'PUT', 'title' => 'Dicas de inverno para autocaravanas', 'slug' => 'dicas-de-inverno',
            'content' => $this->words(30), 'banner' => $blog['banner'], 'meta_title' => 'Inverno na estrada', 'focus_keyword' => 'inverno',
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.slug', 'dicas-de-inverno')
            ->assertJsonPath('data.banner', $blog['banner'])
            ->assertJsonPath('data.meta_title', 'Inverno na estrada')
            ->assertJsonPath('data.focus_keyword', 'inverno');
    }

    public function test_read_time_counts_words_with_accents(): void
    {
        $this->assertSame(4, BlogHtml::wordCount('<p>Ação não é pós-venda</p>'));
        $this->assertSame(6, BlogHtml::wordCount('<p>Inspeção&nbsp;periódica: já está à porta</p>'));

        $blog = $this->create($this->userA, $this->a, ['content' => $this->words(401)]);
        $this->assertSame(3, $blog['read_time']);
    }

    // ── Slug ─────────────────────────────────────────────────────────────────

    public function test_slug_is_unique_per_company(): void
    {
        $this->assertSame('dicas-de-inverno', $this->create($this->userA, $this->a)['slug']);
        $this->assertSame('dicas-de-inverno', $this->create($this->adminB, $this->b)['slug'], 'outra empresa pode usar o mesmo endereço');
        $this->assertSame('dicas-de-inverno-2', $this->create($this->userA, $this->a)['slug']);

        // Escrito à mão e já ocupado na mesma empresa: 422.
        $this->as($this->userA)->postJson($this->url($this->a), ['title' => 'Outro', 'slug' => 'Dicas de Inverno', 'content' => $this->words(5)])
            ->assertStatus(422)->assertJsonValidationErrors('slug');

        // Um artigo apagado continua a ocupar o endereço.
        $gone = $this->create($this->userA, $this->a, ['title' => 'Antigo']);
        $this->as($this->userA)->deleteJson($this->url($this->a, "/{$gone['id']}"))->assertOk();
        $this->assertSame('antigo-2', $this->create($this->userA, $this->a, ['title' => 'Antigo'])['slug']);
    }

    public function test_slug_is_fixed_after_publication(): void
    {
        $blog = $this->create($this->adminA, $this->a);
        $this->as($this->adminA)->postJson($this->url($this->a, "/{$blog['id']}/approve"))->assertOk()->assertJsonPath('data.status', 'published');

        $this->as($this->adminA)->putJson($this->url($this->a, "/{$blog['id']}"), ['title' => 'Novo título', 'slug' => 'outro-endereco', 'content' => $this->words(10)])
            ->assertStatus(422)->assertJsonValidationErrors('slug');
        $this->as($this->adminA)->putJson($this->url($this->a, "/{$blog['id']}"), ['title' => 'Novo título', 'slug' => 'dicas-de-inverno', 'content' => $this->words(10)])
            ->assertOk()->assertJsonPath('data.slug', 'dicas-de-inverno')->assertJsonPath('data.permissions.slug_locked', true);

        // Mesmo depois de voltar a rascunho, o endereço publicado não muda.
        $this->as($this->adminA)->postJson($this->url($this->a, "/{$blog['id']}/back-to-draft"))->assertOk();
        $this->as($this->adminA)->putJson($this->url($this->a, "/{$blog['id']}"), ['title' => 'X', 'slug' => 'outro', 'content' => $this->words(10)])->assertStatus(422);
    }

    // ── Limpeza do HTML ──────────────────────────────────────────────────────

    private const MALICIOUS = '<h1>Título</h1><p onclick="steal()">Olá <script>alert(1)</script><img src=x onerror="alert(2)">'
        . '<a href="javascript:alert(3)">clique</a> <a href="https://quebom.pt" target="_blank">site</a></p>'
        . '<iframe src="https://evil.example"></iframe><p style="color:red">Fim</p><svg onload="alert(4)"></svg>'
        . '<ol><li data-list="bullet"><span class="ql-ui" contenteditable="false"></span>Um</li><li data-list="bullet">Dois</li></ol>';

    private function assertClean(string $html): void
    {
        foreach (['<script', 'alert(', 'onerror', 'onclick', 'javascript:', '<iframe', '<img', 'style=', '<svg', 'onload', '<h1', 'data-list', 'ql-ui'] as $bad) {
            $this->assertStringNotContainsStringIgnoringCase($bad, $html, $bad);
        }
        $this->assertStringContainsString('<h2>Título</h2>', $html);
        $this->assertStringContainsString('<ul><li>Um</li><li>Dois</li></ul>', $html);
        $this->assertMatchesRegularExpression('#<a href="https://quebom.pt" target="_blank" rel="[^"]*noopener[^"]*">site</a>#', $html);
    }

    public function test_html_is_cleaned_when_saving(): void
    {
        $blog = $this->create($this->userA, $this->a, ['content' => self::MALICIOUS, 'title' => '<b>Título</b> <script>x</script>']);

        $stored = DB::table('blogs')->where('id', $blog['id'])->first();
        $this->assertClean($stored->content);
        $this->assertStringNotContainsString('<', $stored->title);
    }

    public function test_html_is_cleaned_when_showing_even_if_stored_raw(): void
    {
        $id = $this->raw($this->a, ['slug' => 'antigo', 'content' => self::MALICIOUS, 'status' => 'published', 'published_at' => now()->subDay()]);

        $this->assertClean($this->as($this->userA)->getJson($this->url($this->a, "/{$id}"))->assertOk()->json('data.content'));
        $this->app['auth']->forgetGuards();
        $this->assertClean($this->getJson('/api/public/blogs/antigo?token=tok-a')->assertOk()->json('data.content'));
        $this->assertClean($this->getJson('/api/public/blogs?token=tok-a')->assertOk()->json('data.0.content'));
    }

    // ── Listagem e tenancy ───────────────────────────────────────────────────

    public function test_root_lists_only_the_company_of_the_route(): void
    {
        $this->raw($this->a, ['title' => 'Da Quebom']);
        $this->raw($this->b, ['title' => 'Da Outra']);
        $this->raw($this->x, ['title' => 'Da XPLENDOR']);

        $titles = array_column($this->as($this->root)->getJson($this->url($this->a))->assertOk()->json('data.page.data'), 'title');
        $this->assertSame(['Da Quebom'], $titles);
    }

    public function test_tenancy_between_companies(): void
    {
        $idB = $this->raw($this->b, ['title' => 'Da Outra']);

        $this->as($this->userA)->getJson($this->url($this->b))->assertStatus(403);
        $this->as($this->userA)->getJson($this->url($this->b, "/{$idB}"))->assertStatus(403);
        // Pela rota da própria empresa, o artigo de outra empresa não existe.
        $this->as($this->userA)->getJson($this->url($this->a, "/{$idB}"))->assertStatus(404);
        $this->as($this->userA)->putJson($this->url($this->a, "/{$idB}"), ['title' => 'X'])->assertStatus(404);
        $this->as($this->userA)->deleteJson($this->url($this->a, "/{$idB}"))->assertStatus(404);
        $this->as($this->adminA)->postJson($this->url($this->a, "/{$idB}/approve"))->assertStatus(404);
        $this->as($this->adminA)->postJson($this->url($this->a, "/{$idB}/submit"))->assertStatus(404);
        $this->assertSame('draft', Blog::find($idB)->status);
    }

    // ── Aprovação ────────────────────────────────────────────────────────────

    public function test_only_the_company_admin_outside_impersonation_approves(): void
    {
        $blog = $this->create($this->userA, $this->a);
        $this->as($this->userA)->postJson($this->url($this->a, "/{$blog['id']}/submit"))->assertOk()->assertJsonPath('data.status', 'in_review');

        $this->as($this->userA)->postJson($this->url($this->a, "/{$blog['id']}/approve"))->assertStatus(403);
        $this->as($this->root)->postJson($this->url($this->a, "/{$blog['id']}/approve"))->assertStatus(403);
        $this->impersonating($this->adminA)->postJson($this->url($this->a, "/{$blog['id']}/approve"))->assertStatus(403);
        $this->as($this->adminB)->postJson($this->url($this->a, "/{$blog['id']}/approve"))->assertStatus(403);
        $this->assertSame('in_review', Blog::find($blog['id'])->status);

        $this->as($this->adminA)->postJson($this->url($this->a, "/{$blog['id']}/approve"))->assertOk()
            ->assertJsonPath('data.status', 'published')->assertJsonPath('data.approved_by_name', $this->adminA->name);
        $this->assertNotNull(Blog::find($blog['id'])->first_published_at);
    }

    public function test_xplendor_team_writes_and_submits_and_admins_are_notified(): void
    {
        $deactivated = User::factory()->create(['company_id' => $this->a->id, 'role' => 'admin', 'email' => 'saiu@quebom.pt', 'deactivated_at' => now()]);

        $byRoot = $this->create($this->root, $this->a, ['title' => 'Escrito pela equipa']);
        $this->as($this->root)->postJson($this->url($this->a, "/{$byRoot['id']}/submit"))->assertOk()->assertJsonPath('data.status', 'in_review');

        $viaImpersonation = $this->impersonating($this->adminA)->postJson($this->url($this->a), ['title' => 'Em sessão', 'content' => $this->words(10)])->assertStatus(201)->json('data');
        $this->assertFalse($viaImpersonation['permissions']['is_approver']);
        $this->impersonating($this->adminA)->postJson($this->url($this->a, "/{$viaImpersonation['id']}/submit"))->assertOk();

        $this->assertSame(2, Alert::where('company_id', $this->a->id)->where('title', 'Artigo para rever')->count());
        $this->assertSame(0, Alert::where('company_id', $this->b->id)->count());
        Mail::assertQueued(BlogReviewRequestedMail::class, 2);
        Mail::assertQueued(BlogReviewRequestedMail::class, fn ($m) => $m->hasTo('admin@quebom.pt') && $m->title === 'Escrito pela equipa' && str_ends_with($m->url, "/blogs/{$byRoot['id']}"));
        Mail::assertNotQueued(BlogReviewRequestedMail::class, fn ($m) => $m->hasTo('user@quebom.pt') || $m->hasTo('admin@outra.pt') || $m->hasTo($deactivated->email));
    }

    public function test_verify_marker_blocks_only_the_approval(): void
    {
        $blog = $this->create($this->userA, $this->a, ['content' => '<p>Garantia de [VERIFICAR: prazo] meses.</p>', 'meta_description' => 'Sem marcas']);
        $this->as($this->userA)->postJson($this->url($this->a, "/{$blog['id']}/submit"))->assertOk();

        $res = $this->as($this->adminA)->postJson($this->url($this->a, "/{$blog['id']}/approve"))->assertStatus(422);
        $this->assertStringContainsString('Texto', $res->json('errors.markers.0'));
        $this->as($this->adminA)->getJson($this->url($this->a, "/{$blog['id']}"))->assertJsonPath('data.unresolved_markers', ['Texto']);

        $this->as($this->adminA)->putJson($this->url($this->a, "/{$blog['id']}"), ['title' => 'Dicas de inverno', 'content' => '<p>Garantia de 24 meses.</p>'])->assertOk();
        $this->as($this->adminA)->postJson($this->url($this->a, "/{$blog['id']}/approve"))->assertOk();

        // Um artigo publicado não pode voltar a ter "[VERIFICAR]".
        $this->as($this->adminA)->putJson($this->url($this->a, "/{$blog['id']}"), ['title' => 'Dicas [verificar]', 'content' => '<p>Texto</p>'])->assertStatus(422);
    }

    public function test_request_changes_and_back_to_draft(): void
    {
        $blog = $this->create($this->userA, $this->a);
        $this->as($this->userA)->postJson($this->url($this->a, "/{$blog['id']}/submit"))->assertOk();

        $this->as($this->userA)->postJson($this->url($this->a, "/{$blog['id']}/request-changes"), ['note' => 'X'])->assertStatus(403);
        $this->as($this->adminA)->postJson($this->url($this->a, "/{$blog['id']}/request-changes"), [])->assertStatus(422);
        $this->as($this->adminA)->postJson($this->url($this->a, "/{$blog['id']}/request-changes"), ['note' => 'Rever a introdução.'])->assertOk()
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.review_note', 'Rever a introdução.');

        // O autor corrige e volta a enviar; o admin aprova; o autor já não pode alterar.
        $this->as($this->userA)->putJson($this->url($this->a, "/{$blog['id']}"), ['title' => 'Dicas de inverno', 'content' => $this->words(60)])->assertOk();
        $this->as($this->userA)->postJson($this->url($this->a, "/{$blog['id']}/submit"))->assertOk()->assertJsonPath('data.review_note', null);
        $this->as($this->adminA)->postJson($this->url($this->a, "/{$blog['id']}/approve"))->assertOk();
        $this->as($this->userA)->putJson($this->url($this->a, "/{$blog['id']}"), ['title' => 'Alterado', 'content' => $this->words(5)])->assertStatus(403);
        $this->as($this->userA)->deleteJson($this->url($this->a, "/{$blog['id']}"))->assertStatus(403);
        $this->impersonating($this->adminA)->postJson($this->url($this->a, "/{$blog['id']}/back-to-draft"))->assertStatus(403);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/public/blogs/dicas-de-inverno?token=tok-a')->assertOk();
        $this->as($this->adminA)->postJson($this->url($this->a, "/{$blog['id']}/back-to-draft"))->assertOk()->assertJsonPath('data.status', 'draft');
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/public/blogs/dicas-de-inverno?token=tok-a')->assertStatus(404);
    }

    // ── Agendamento ──────────────────────────────────────────────────────────

    public function test_approved_with_future_date_is_published_by_the_job(): void
    {
        $this->travelTo(now()->setTime(10, 0));
        $blog = $this->create($this->adminA, $this->a);
        $when = now()->addHours(2);

        $this->as($this->adminA)->postJson($this->url($this->a, "/{$blog['id']}/approve"), ['publish_at' => $when->toIso8601String()])->assertOk()
            ->assertJsonPath('data.status', 'approved');
        $this->assertNull(Blog::find($blog['id'])->first_published_at);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/public/blogs?token=tok-a')->assertOk()->assertJsonCount(0, 'data');

        // Antes da hora, o job não publica.
        (new PublishScheduledBlogsJob())->handle(app(BlogWorkflowService::class));
        $this->assertSame('approved', Blog::find($blog['id'])->status);

        $this->travelTo($when->copy()->addMinutes(3));
        (new PublishScheduledBlogsJob())->handle(app(BlogWorkflowService::class));
        $fresh = Blog::find($blog['id']);
        $this->assertSame('published', $fresh->status);
        $this->assertEquals($when->startOfSecond(), $fresh->first_published_at);
        $this->getJson('/api/public/blogs?token=tok-a')->assertOk()->assertJsonPath('data.0.slug', 'dicas-de-inverno');
    }

    public function test_the_job_is_scheduled_every_five_minutes(): void
    {
        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->first(fn ($e) => $e->description === 'blogs-publish-scheduled');
        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
    }

    // ── Saída pública ────────────────────────────────────────────────────────

    public function test_public_output_falls_back_for_seo_fields(): void
    {
        $this->raw($this->a, ['slug' => 'sem-seo', 'title' => 'Sem SEO', 'excerpt' => 'Resumo curto.', 'status' => 'published', 'published_at' => now()->subHour(), 'banner' => '/storage/x.webp']);

        $this->getJson('/api/public/blogs/sem-seo?token=tok-a')->assertOk()
            ->assertJsonPath('data.meta_title', 'Sem SEO')
            ->assertJsonPath('data.meta_description', 'Resumo curto.')
            ->assertJsonPath('data.og_title', 'Sem SEO')
            ->assertJsonPath('data.og_image', '/storage/x.webp');
    }
}
