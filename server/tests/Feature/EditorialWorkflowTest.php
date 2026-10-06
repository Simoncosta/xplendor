<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\EditorialPost;
use App\Models\EditorialPostEvent;
use App\Models\EditorialPostReview;
use App\Models\EditorialPostVersion;
use App\Models\ImpersonationSession;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Linha Editorial, F3a: etapas e passagens por papel, aprovação dentro da XPLENDOR (nunca
 * o root nem em impersonation), versão congelada no envio, nova versão depois de pedir
 * alterações, uma aprovação por versão, revisão interna por outra pessoa, comentários
 * internos, pessoa real no histórico (impersonator_user_id), conversão do estado antigo e
 * tenancy.
 */
class EditorialWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;
    private Company $b;
    private User $adminA;
    private User $userA;
    private User $approverA;
    private User $otherUserA;
    private User $adminB;
    private User $root;

    protected function setUp(): void
    {
        parent::setUp();
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->a = Company::create(['nipc' => '500070001', 'fiscal_name' => 'Quebom Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->b = Company::create(['nipc' => '500070002', 'fiscal_name' => 'Outra Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $x = Company::create(['nipc' => '500070003', 'fiscal_name' => 'XPLENDOR', 'plan_id' => $planId, 'subscription_status' => 'active']);
        foreach ([$this->a, $this->b] as $c) {
            CompanyModule::create(['company_id' => $c->id, 'module_key' => 'linha_editorial']);
        }
        $this->adminA = User::factory()->create(['company_id' => $this->a->id, 'role' => 'admin', 'name' => 'Matilde']);
        $this->userA = User::factory()->create(['company_id' => $this->a->id, 'role' => 'user', 'name' => 'Rui']);
        $this->approverA = User::factory()->create(['company_id' => $this->a->id, 'role' => 'user', 'name' => 'Inês']);
        $this->approverA->forceFill(['can_approve_content' => true])->save();
        $this->otherUserA = User::factory()->create(['company_id' => $this->a->id, 'role' => 'user', 'name' => 'Joana']);
        $this->adminB = User::factory()->create(['company_id' => $this->b->id, 'role' => 'admin']);
        $this->root = User::factory()->create(['company_id' => $x->id, 'role' => 'root', 'name' => 'Ana Designer']);
    }

    // ── auxiliares ───────────────────────────────────────────────────────────

    private function url(Company $c, string $suffix): string
    {
        return "/api/v1/companies/{$c->id}/editorial{$suffix}";
    }

    private function as(User $u): self
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u, 'sanctum');
    }

    /** A equipa (root) em sessão como o utilizador indicado: o token é do alvo. */
    private function impersonating(User $target): self
    {
        $this->app['auth']->forgetGuards();
        $nt = $target->createToken('impersonation', ['impersonation'], now()->addMinutes(30));
        ImpersonationSession::create(['root_id' => $this->root->id, 'target_user_id' => $target->id, 'company_id' => $target->company_id,
            'token_id' => $nt->accessToken->getKey(), 'ip' => '127.0.0.1', 'user_agent' => 'test', 'started_at' => now()]);

        return $this->withHeaders(['Authorization' => 'Bearer ' . $nt->plainTextToken, 'Accept' => 'application/json']);
    }

    private function makePost(Company $c, array $extra = []): EditorialPost
    {
        return EditorialPost::create(array_merge([
            'company_id' => $c->id, 'publish_date' => now()->addDays(10)->toDateString(), 'title' => 'Reel de inverno',
            'format' => 'Reels', 'channel' => 'instagram', 'stage' => EditorialPost::STAGE_PLANNING,
        ], $extra));
    }

    private function move(User|self $who, EditorialPost $p, string $stage, ?Company $c = null)
    {
        $req = $who instanceof User ? $this->as($who) : $who;

        return $req->postJson($this->url($c ?? $this->a, "/posts/{$p->id}/move"), ['stage' => $stage]);
    }

    private function write(User|self $who, EditorialPost $p, string $caption = 'Legenda de inverno.')
    {
        $req = $who instanceof User ? $this->as($who) : $who;

        return $req->putJson($this->url($this->a, "/posts/{$p->id}/content"), ['caption' => $caption, 'hashtags' => ['inverno', '#estrada']]);
    }

    /** Publicação em Aprovação, com a versão 1 enviada. */
    private function inClientReview(): EditorialPost
    {
        $p = $this->makePost($this->a, ['stage' => EditorialPost::STAGE_PRODUCTION]);
        $this->write($this->userA, $p)->assertOk();
        $this->move($this->userA, $p, EditorialPost::STAGE_CLIENT_REVIEW)->assertOk();

        return $p->fresh();
    }

    // ── Passagens por papel ──────────────────────────────────────────────────

    public function test_producer_moves_only_through_allowed_stages(): void
    {
        $p = $this->makePost($this->a, ['stage' => EditorialPost::STAGE_IDEA]);

        $this->move($this->userA, $p, 'planning')->assertOk()->assertJsonPath('data.post.stage', 'planning');
        $this->move($this->userA, $p, 'scheduled')->assertStatus(422);
        $this->move($this->userA, $p, 'client_review')->assertStatus(422);
        $this->move($this->userA, $p, 'production')->assertOk()->assertJsonPath('data.moves.client_review', null);
        // Enviar sem legenda: recusado.
        $this->move($this->userA, $p, 'client_review')->assertStatus(422)->assertJsonPath('message', 'Escreva a legenda antes de enviar.');
        $this->move($this->userA, $p, 'published')->assertStatus(422);
        $this->move($this->userA, $p, 'nao-existe')->assertStatus(422);

        $this->write($this->userA, $p)->assertOk()->assertJsonPath('data.versions.0.hashtags', ['#inverno', '#estrada']);
        $this->move($this->userA, $p, 'client_review')->assertOk();
        // O cliente aprova; depois só se avança para Publicado e Análise.
        $this->as($this->adminA)->postJson($this->url($this->a, "/posts/{$p->id}/approve"))->assertOk()->assertJsonPath('data.post.stage', 'scheduled');
        $this->move($this->userA, $p, 'production')->assertStatus(422);
        $this->move($this->userA, $p, 'published')->assertOk();
        $this->move($this->userA, $p, 'analysis')->assertOk();
        $this->move($this->userA, $p, 'scheduled')->assertStatus(422);
        $this->write($this->userA, $p, 'Depois de publicado')->assertStatus(409);

        $this->assertSame('otimizada', $p->fresh()->status, 'O estado antigo segue a etapa.');
    }

    public function test_approval_by_role_never_root_nor_impersonation(): void
    {
        foreach ([[$this->userA, 403], [$this->root, 403], [$this->adminB, 403]] as [$who, $status]) {
            $p = $this->inClientReview();
            $this->as($who)->postJson($this->url($this->a, "/posts/{$p->id}/approve"))->assertStatus($status);
            $this->assertSame('client_review', $p->fresh()->stage);
        }

        $p = $this->inClientReview();
        $this->impersonating($this->adminA)->postJson($this->url($this->a, "/posts/{$p->id}/approve"))->assertStatus(403);
        $this->impersonating($this->adminA)->postJson($this->url($this->a, "/posts/{$p->id}/request-changes"), ['message' => 'x'])->assertStatus(403);
        $this->assertSame('client_review', $p->fresh()->stage);

        // O aprovador marcado (perfil "user") e o administrador aprovam.
        $this->as($this->approverA)->postJson($this->url($this->a, "/posts/{$p->id}/approve"), ['message' => 'Perfeito'])->assertOk();
        $p2 = $this->inClientReview();
        $this->as($this->adminA)->postJson($this->url($this->a, "/posts/{$p2->id}/approve"))->assertOk();

        $review = EditorialPostReview::where('editorial_post_id', $p->id)->sole();
        $this->assertSame(['approved', 'app', 'Inês', 'Perfeito'], [$review->decision, $review->via, $review->reviewer_name, $review->message]);
    }

    public function test_root_and_impersonation_still_produce_and_move(): void
    {
        $p = $this->makePost($this->a, ['stage' => EditorialPost::STAGE_PRODUCTION]);

        $this->as($this->root)->putJson($this->url($this->a, "/posts/{$p->id}/content"), ['caption' => 'Feito pela equipa'])->assertOk();
        $this->impersonating($this->userA)->postJson($this->url($this->a, "/posts/{$p->id}/move"), ['stage' => 'internal_review'])->assertOk();
        $this->impersonating($this->userA)->postJson($this->url($this->a, "/posts/{$p->id}/move"), ['stage' => 'client_review'])->assertOk();
    }

    // ── Versões ──────────────────────────────────────────────────────────────

    public function test_version_is_frozen_on_send_and_editing_creates_the_next_one(): void
    {
        $p = $this->inClientReview();
        $v1 = EditorialPostVersion::where('editorial_post_id', $p->id)->sole();
        $this->assertSame(['sent', 1], [$v1->status, $v1->number]);
        $this->assertNotNull($v1->frozen_at);
        $this->assertNotNull($v1->sent_at);

        // Editar enquanto o cliente revê: nova versão, a 1 fica intacta e a publicação volta a Produção.
        $res = $this->write($this->userA, $p, 'Legenda corrigida')->assertOk();
        $this->assertSame(['production', 2, 'draft'], [$res->json('data.post.stage'), $res->json('data.versions.0.number'), $res->json('data.versions.0.status')]);
        $this->assertSame(['Legenda de inverno.', 'superseded'], [$v1->fresh()->caption, $v1->fresh()->status]);
        // Editar a versão em rascunho não cria outra.
        $this->write($this->userA, $p, 'Legenda corrigida outra vez')->assertOk()->assertJsonCount(2, 'data.versions');
    }

    public function test_changes_request_leads_to_a_new_version_and_a_new_approval(): void
    {
        $p = $this->inClientReview();

        $this->as($this->adminA)->postJson($this->url($this->a, "/posts/{$p->id}/request-changes"), ['message' => ''])->assertStatus(422);
        $this->as($this->adminA)->postJson($this->url($this->a, "/posts/{$p->id}/request-changes"), ['message' => 'Tirem o preço.'])->assertOk()
            ->assertJsonPath('data.post.stage', 'production')
            ->assertJsonPath('data.versions.0.status', 'changes_requested')
            ->assertJsonPath('data.reviews.0.message', 'Tirem o preço.');
        $this->assertNotNull($p->fresh()->changes_requested_at);

        $this->write($this->userA, $p, 'Sem preço.')->assertOk()->assertJsonPath('data.versions.0.number', 2);
        $this->move($this->userA, $p, 'client_review')->assertOk();
        $this->assertNull($p->fresh()->changes_requested_at);
        $this->as($this->adminA)->postJson($this->url($this->a, "/posts/{$p->id}/approve"))->assertOk()->assertJsonPath('data.post.stage', 'scheduled');

        $p->refresh();
        $v2 = EditorialPostVersion::where('editorial_post_id', $p->id)->where('number', 2)->sole();
        $this->assertSame([$v2->id, 'approved'], [$p->approved_version_id, $v2->status]);
        $this->assertSame('superseded', EditorialPostVersion::where('editorial_post_id', $p->id)->where('number', 1)->value('status'));
        $this->assertSame(['approved', 'changes_requested'], EditorialPostReview::where('editorial_post_id', $p->id)->orderByDesc('id')->pluck('decision')->all());
    }

    public function test_one_approval_per_version(): void
    {
        $p = $this->inClientReview();
        $this->as($this->adminA)->postJson($this->url($this->a, "/posts/{$p->id}/approve"))->assertOk();
        // Segunda aprovação da mesma publicação: já não está à espera.
        $this->as($this->approverA)->postJson($this->url($this->a, "/posts/{$p->id}/approve"))->assertStatus(422);

        // A base de dados também recusa uma segunda aprovação da mesma versão.
        $versionId = $p->fresh()->approved_version_id;
        $this->expectException(QueryException::class);
        EditorialPostReview::create(['company_id' => $this->a->id, 'editorial_post_id' => $p->id, 'version_id' => $versionId,
            'decision' => 'approved', 'approved_version_id' => $versionId, 'created_at' => now()]);
    }

    public function test_approve_all_only_touches_waiting_posts_of_the_company(): void
    {
        $waiting = [$this->inClientReview(), $this->inClientReview()];
        $planning = $this->makePost($this->a);
        $other = $this->makePost($this->b, ['stage' => EditorialPost::STAGE_CLIENT_REVIEW]);

        $ids = [$waiting[0]->id, $waiting[1]->id, $planning->id, $other->id];
        $this->as($this->userA)->postJson($this->url($this->a, '/approvals/approve-all'), ['post_ids' => $ids])->assertStatus(403);
        $res = $this->as($this->adminA)->postJson($this->url($this->a, '/approvals/approve-all'), ['post_ids' => $ids])->assertOk();

        $this->assertEqualsCanonicalizing([$waiting[0]->id, $waiting[1]->id], $res->json('data.approved'));
        $this->assertSame(['planning', 'client_review'], [$planning->fresh()->stage, $other->fresh()->stage]);
    }

    // ── Revisão interna e empresas sem aprovação ─────────────────────────────

    public function test_internal_review_must_be_done_by_another_person(): void
    {
        $this->a->forceFill(['internal_review_required' => true])->save();
        $p = $this->makePost($this->a, ['stage' => EditorialPost::STAGE_PRODUCTION]);

        // A designer escreve em sessão como o Rui: conta como ela própria.
        $this->write($this->impersonating($this->userA), $p, 'Pela equipa')->assertOk();
        $this->move($this->userA, $p, 'client_review')->assertStatus(422)->assertJsonPath('message', 'A empresa exige a revisão interna antes do cliente.');
        $this->move($this->userA, $p, 'internal_review')->assertOk();
        $this->move($this->root, $p, 'client_review')->assertStatus(422)->assertJsonPath('message', 'A revisão interna tem de ser feita por outra pessoa.');
        $this->move($this->otherUserA, $p, 'client_review')->assertOk();
    }

    public function test_company_without_client_approval_goes_straight_to_scheduled(): void
    {
        $this->a->forceFill(['content_approval_required' => false])->save();
        $p = $this->makePost($this->a, ['stage' => EditorialPost::STAGE_PRODUCTION]);
        $this->write($this->userA, $p)->assertOk();

        $this->move($this->userA, $p, 'client_review')->assertStatus(422);
        $this->move($this->userA, $p, 'scheduled')->assertOk();
        $p->refresh();
        $this->assertSame('approved', EditorialPostVersion::find($p->approved_version_id)->status);
    }

    // ── Comentários e pessoa real ────────────────────────────────────────────

    public function test_internal_comments_are_only_for_the_team(): void
    {
        $p = $this->makePost($this->a, ['stage' => EditorialPost::STAGE_PRODUCTION]);

        $this->as($this->root)->postJson($this->url($this->a, "/posts/{$p->id}/comments"), ['body' => 'Falta a foto do stand.', 'visibility' => 'internal'])->assertOk();
        $this->as($this->userA)->postJson($this->url($this->a, "/posts/{$p->id}/comments"), ['body' => 'Interno?', 'visibility' => 'internal'])->assertStatus(422);
        $this->as($this->userA)->postJson($this->url($this->a, "/posts/{$p->id}/comments"), ['body' => 'Podem usar a foto nova?'])->assertOk();

        $client = $this->as($this->adminA)->getJson($this->url($this->a, "/posts/{$p->id}/workflow"))->assertOk()->json('data');
        $this->assertSame(['Podem usar a foto nova?'], array_column($client['comments'], 'body'));
        $this->assertNotContains('comment_internal', array_column($client['events'], 'type'));

        $team = $this->as($this->root)->getJson($this->url($this->a, "/posts/{$p->id}/workflow"))->assertOk()->json('data');
        $this->assertCount(2, $team['comments']);
        $this->assertSame('Ana Designer (equipa XPLENDOR)', $team['comments'][0]['author']);
    }

    public function test_every_action_records_the_real_person(): void
    {
        $p = $this->makePost($this->a, ['stage' => EditorialPost::STAGE_PRODUCTION]);
        $team = $this->impersonating($this->userA);

        $team->putJson($this->url($this->a, "/posts/{$p->id}/content"), ['caption' => 'Pela equipa'])->assertOk();
        $team->postJson($this->url($this->a, "/posts/{$p->id}/move"), ['stage' => 'client_review'])->assertOk();
        $team->postJson($this->url($this->a, "/posts/{$p->id}/comments"), ['body' => 'Enviado.'])->assertOk();

        $version = EditorialPostVersion::where('editorial_post_id', $p->id)->sole();
        $this->assertSame([$this->userA->id, $this->root->id], [$version->created_by_user_id, $version->impersonator_user_id]);
        $events = EditorialPostEvent::where('editorial_post_id', $p->id)->get();
        $this->assertCount(3, $events);
        $this->assertSame([$this->root->id], $events->pluck('impersonator_user_id')->unique()->values()->all());

        $who = array_column($this->as($this->adminA)->getJson($this->url($this->a, "/posts/{$p->id}/workflow"))->json('data.events'), 'who');
        $this->assertSame(['Ana Designer (equipa XPLENDOR)'], array_values(array_unique($who)));
    }

    // ── Modo de produção ─────────────────────────────────────────────────────

    public function test_client_managed_by_the_team_comments_and_approves_but_does_not_produce(): void
    {
        $this->a->forceFill(['content_production_mode' => 'team'])->save();
        $p = $this->makePost($this->a, ['stage' => EditorialPost::STAGE_PRODUCTION]);

        foreach ([$this->userA, $this->adminA, $this->approverA] as $client) {
            $this->write($client, $p)->assertStatus(403)->assertJsonPath('message', 'Nesta empresa a produção é feita pela equipa XPLENDOR: pode comentar, aprovar ou pedir alterações.');
            $this->move($client, $p, 'internal_review')->assertStatus(403);
            $this->as($client)->postJson($this->url($this->a, '/posts'), ['title' => 'Nova', 'publish_date' => now()->addDays(5)->toDateString(), 'format' => 'Reels', 'channel' => 'instagram'])->assertStatus(403);
            $this->as($client)->putJson($this->url($this->a, "/posts/{$p->id}"), ['title' => 'Mudada', 'publish_date' => $p->publish_date->toDateString(), 'format' => 'Reels', 'channel' => 'instagram'])->assertStatus(403);
            $this->as($client)->deleteJson($this->url($this->a, "/posts/{$p->id}"))->assertStatus(403);
            $this->as($client)->postJson($this->url($this->a, "/posts/{$p->id}/creative-suggestions"))->assertStatus(403);
            $this->as($client)->postJson($this->url($this->a, '/ideas'), ['year' => (int) now()->year, 'month' => (int) now()->month])->assertStatus(403);

            $detail = $this->as($client)->getJson($this->url($this->a, "/posts/{$p->id}/workflow"))->assertOk()->json('data');
            $this->assertSame([false, false, []], [$detail['permissions']['can_produce'], $detail['permissions']['can_edit_content'], $detail['moves']]);
            $board = $this->as($client)->getJson($this->url($this->a, '/board?month=' . $p->publish_date->format('Y-m')))->assertOk()->json('data');
            $this->assertFalse($board['can_produce']);
            $this->assertSame([], $board['posts'][0]['moves']);
            // Comentar continua a ser possível.
            $this->as($client)->postJson($this->url($this->a, "/posts/{$p->id}/comments"), ['body' => 'Gosto da ideia.'])->assertOk();
        }
        $this->assertSame(['Reel de inverno', 'production', null], [$p->fresh()->title, $p->fresh()->stage, $p->fresh()->current_version_id]);

        // A equipa produz (root ou em sessão como cliente) e o cliente aprova.
        $this->write($this->impersonating($this->userA), $p, 'Pela equipa')->assertOk();
        $this->move($this->root, $p, 'client_review')->assertOk();
        $this->as($this->adminA)->postJson($this->url($this->a, "/posts/{$p->id}/request-changes"), ['message' => 'Outra foto.'])->assertOk();
        $this->write($this->root, $p, 'Com outra foto')->assertOk();
        $this->move($this->root, $p, 'client_review')->assertOk();
        $this->as($this->approverA)->postJson($this->url($this->a, "/posts/{$p->id}/approve"))->assertOk()->assertJsonPath('data.post.stage', 'scheduled');
        $this->move($this->adminA, $p, 'published')->assertStatus(403);
        $this->move($this->root, $p, 'published')->assertOk();
    }

    public function test_own_production_is_the_default_and_unchanged(): void
    {
        $this->assertSame('self', $this->as($this->adminA)->getJson($this->url($this->a, '/workflow-settings'))->json('data.production_mode'));
        $p = $this->makePost($this->a, ['stage' => EditorialPost::STAGE_PRODUCTION]);

        $this->write($this->userA, $p)->assertOk();
        $this->move($this->userA, $p, 'client_review')->assertOk();
        $detail = $this->as($this->userA)->getJson($this->url($this->a, "/posts/{$p->id}/workflow"))->json('data');
        $this->assertTrue($detail['permissions']['can_produce']);
    }

    public function test_only_the_team_changes_the_production_mode(): void
    {
        $body = fn (string $mode) => ['content_approval_required' => true, 'internal_review_required' => false, 'production_mode' => $mode];

        $this->as($this->adminA)->putJson($this->url($this->a, '/workflow-settings'), $body('team'))->assertStatus(403);
        $this->assertSame('self', $this->a->fresh()->content_production_mode);
        // O administrador continua a poder mudar as outras definições (sem mudar o modo).
        $this->as($this->adminA)->putJson($this->url($this->a, '/workflow-settings'), $body('self'))->assertOk();

        $this->impersonating($this->adminA)->putJson($this->url($this->a, '/workflow-settings'), $body('team'))->assertOk()->assertJsonPath('data.production_mode', 'team');
        $this->as($this->root)->putJson($this->url($this->a, '/workflow-settings'), $body('self'))->assertOk()->assertJsonPath('data.production_mode', 'self');
        $this->as($this->root)->putJson($this->url($this->a, '/workflow-settings'), $body('outro'))->assertStatus(422);
    }

    // ── Estado antigo, Site e tenancy ────────────────────────────────────────

    public function test_old_status_is_converted_to_stages(): void
    {
        foreach (['rascunho' => 'planning', 'revisao' => 'internal_review', 'publicada' => 'published', 'otimizada' => 'analysis'] as $status => $stage) {
            $id = DB::table('editorial_posts')->insertGetId(['company_id' => $this->a->id, 'publish_date' => '2026-12-01', 'title' => $status,
                'format' => 'Reels', 'status' => $status, 'stage' => 'planning', 'channel' => 'instagram', 'created_at' => now(), 'updated_at' => now()]);
            $expected[$id] = $stage;
        }

        $migration = require database_path('migrations/2026_11_18_100000_create_editorial_production_workflow.php');
        $migration->backfillStages();

        foreach ($expected as $id => $stage) {
            $this->assertSame($stage, DB::table('editorial_posts')->where('id', $id)->value('stage'));
        }
    }

    public function test_site_posts_follow_the_blog_flow(): void
    {
        $p = $this->makePost($this->a, ['channel' => 'site', 'format' => 'Artigo']);

        $this->move($this->userA, $p, 'production')->assertStatus(422)->assertJsonPath('message', 'As publicações do Site seguem o fluxo do blog.');
        $this->write($this->userA, $p)->assertStatus(422);
    }

    public function test_tenancy(): void
    {
        $theirs = $this->makePost($this->b, ['stage' => EditorialPost::STAGE_CLIENT_REVIEW]);

        $this->as($this->userA)->getJson($this->url($this->a, "/posts/{$theirs->id}/workflow"))->assertNotFound();
        $this->as($this->userA)->getJson($this->url($this->b, "/posts/{$theirs->id}/workflow"))->assertStatus(403);
        $this->as($this->userA)->postJson($this->url($this->a, "/posts/{$theirs->id}/move"), ['stage' => 'production'])->assertNotFound();
        $this->as($this->adminA)->postJson($this->url($this->a, "/posts/{$theirs->id}/approve"))->assertNotFound();
        $this->as($this->adminA)->getJson($this->url($this->b, '/board?month=2026-12'))->assertStatus(403);
        $this->assertSame('client_review', $theirs->fresh()->stage);

        // Quadro: só as publicações da empresa, com o que cada um pode fazer.
        $mine = $this->makePost($this->a, ['publish_date' => now()->addDays(3)->toDateString()]);
        $board = $this->as($this->userA)->getJson($this->url($this->a, '/board?month=' . now()->addDays(3)->format('Y-m')))->assertOk()->json('data');
        $this->assertContains($mine->id, array_column($board['posts'], 'id'));
        $this->assertNotContains($theirs->id, array_column($board['posts'], 'id'));

        // Definições: o utilizador comum não altera; aprovadores só pelo admin da própria empresa, fora de impersonation.
        $this->as($this->userA)->putJson($this->url($this->a, '/workflow-settings'), ['content_approval_required' => false, 'internal_review_required' => true])->assertStatus(403);
        $this->as($this->adminA)->putJson($this->url($this->a, '/workflow-settings'), ['content_approval_required' => true, 'internal_review_required' => true])->assertOk();
        $this->impersonating($this->adminA)->putJson($this->url($this->a, "/approvers/{$this->otherUserA->id}"), ['can_approve_content' => true])->assertStatus(403);
        $this->as($this->adminA)->putJson($this->url($this->a, "/approvers/{$this->adminB->id}"), ['can_approve_content' => true])->assertNotFound();
        $this->as($this->adminA)->putJson($this->url($this->a, "/approvers/{$this->otherUserA->id}"), ['can_approve_content' => true])->assertOk();
        $this->assertTrue((bool) $this->otherUserA->fresh()->can_approve_content);
        $this->assertFalse((bool) $this->adminB->fresh()->can_approve_content);
    }
}
