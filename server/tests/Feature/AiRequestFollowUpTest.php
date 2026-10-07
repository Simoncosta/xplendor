<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessAiRequestJob;
use App\Models\AiRequest;
use App\Models\Alert;
use App\Models\Company;
use App\Models\User;
use App\Services\Ai\AiRequestLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Pedidos à IA sem prender o ecrã (fornecedores de IA sempre simulados): aviso no sino ao terminar ou
 * falhar, motivo do erro em linguagem simples, pedido parado ao fim de 3 minutos (registado
 * uma vez), trabalho interrompido na fila, e o resultado "à espera" ao voltar à página
 * (último pedido do contexto, descartar) com tenancy.
 */
class AiRequestFollowUpTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\FakesAi;

    private Company $a;
    private Company $b;
    private User $userA;
    private User $otherA;
    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAi();
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->a = Company::create(['nipc' => '500060001', 'fiscal_name' => 'Quebom Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->b = Company::create(['nipc' => '500060002', 'fiscal_name' => 'Outra Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->userA = User::factory()->create(['company_id' => $this->a->id, 'role' => 'user']);
        $this->otherA = User::factory()->create(['company_id' => $this->a->id, 'role' => 'user']);
        $this->userB = User::factory()->create(['company_id' => $this->b->id, 'role' => 'user']);
    }

    private function as(User $u): self
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u, 'sanctum');
    }

    private function url(Company $c, string $suffix): string
    {
        return "/api/v1/companies/{$c->id}{$suffix}";
    }

    private function blogDraft(User $u, Company $c)
    {
        return $this->as($u)->postJson($this->url($c, '/blog-ai/drafts'), ['mode' => 'topic', 'topic' => 'Inverno na estrada']);
    }

    private function request(Company $c, array $extra = []): AiRequest
    {
        return AiRequest::create(array_merge([
            'company_id' => $c->id, 'user_id' => $this->userA->id, 'mode' => AiRequest::MODE_BRAND_PROFILE, 'status' => AiRequest::QUEUED,
            'input' => [], 'model' => 'gpt-4o', 'prompt_version' => 'x',
        ], $extra));
    }

    // ── Sino e motivo do erro ────────────────────────────────────────────────

    public function test_done_request_notifies_the_bell_with_a_link_to_the_waiting_result(): void
    {
        $this->fakeAi([
            'title' => 'Inverno', 'slug' => 'inverno', 'meta_title' => 'Inverno', 'meta_description' => 'x', 'excerpt' => 'x',
            'content' => '<p>A revisão de inverno começa pela água.</p>', 'review_notes' => [],
        ]);

        $id = $this->blogDraft($this->userA, $this->a)->assertStatus(202)->assertJsonPath('data.status', 'done')->json('data.id');

        $alert = Alert::where('company_id', $this->a->id)->sole();
        $this->assertSame(['O rascunho do artigo está pronto', 'opportunity', "/blogs/create?ai_request={$id}"], [$alert->title, $alert->type, $alert->detail_path]);
    }

    public function test_failures_show_the_reason_in_plain_language_and_notify(): void
    {
        // 401 (não se repete); 429 três vezes (repete e desiste); 400.
        Http::fakeSequence('api.anthropic.com/*')
            ->push(['error' => ['message' => 'x']], 401)
            ->push(['error' => ['message' => 'x']], 429)->push(['error' => ['message' => 'x']], 429)->push(['error' => ['message' => 'x']], 429)
            ->push(['error' => ['message' => 'x']], 400);
        foreach (['não está configurado corretamente', 'demasiados pedidos', 'Não foi possível concluir o pedido'] as $expected) {
            $res = $this->blogDraft($this->userA, $this->a)->assertStatus(202)->assertJsonPath('data.status', 'error');
            $this->assertStringContainsString($expected, $res->json('data.error_message'));
        }

        config(['ai.providers.anthropic.key' => '']);
        $this->assertStringContainsString('não está configurado', $this->blogDraft($this->userA, $this->a)->json('data.error_message'));

        $this->assertSame(4, Alert::where('company_id', $this->a->id)->where('type', 'warning')->where('title', 'Não foi possível gerar o rascunho do artigo')->count());
        $this->assertStringContainsString('não respondeu a tempo', AiRequestLifecycle::failureMessage(new \Illuminate\Http\Client\ConnectionException('timeout')));
    }

    // ── Parado e interrompido ────────────────────────────────────────────────

    public function test_request_without_answer_for_three_minutes_is_flagged_and_logged_once(): void
    {
        Log::spy();
        $fresh = $this->request($this->a);
        $old = $this->request($this->a);
        $old->forceFill(['created_at' => now()->subMinutes(4)])->save();

        $this->as($this->userA)->getJson($this->url($this->a, "/brand-profile/suggestions/{$fresh->id}"))
            ->assertOk()->assertJsonPath('data.stalled', false)->assertJsonPath('data.stalled_message', null);

        foreach ([1, 2] as $_) {
            $this->as($this->userA)->getJson($this->url($this->a, "/brand-profile/suggestions/{$old->id}"))
                ->assertOk()->assertJsonPath('data.status', 'queued')->assertJsonPath('data.stalled', true)
                ->assertJsonPath('data.stalled_message', AiRequestLifecycle::MSG_STALLED);
        }
        $this->assertNotNull($old->fresh()->stalled_logged_at);
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains($msg, 'há mais de 3 minutos'))->once();
    }

    public function test_job_that_dies_in_the_queue_closes_the_request_with_an_error(): void
    {
        $pending = $this->request($this->a, ['status' => AiRequest::PROCESSING]);
        $done = $this->request($this->a, ['status' => AiRequest::DONE, 'result' => ['ok' => true]]);

        (new ProcessAiRequestJob($pending->id))->failed(new \RuntimeException('Job timed out'));
        (new ProcessAiRequestJob($done->id))->failed(new \RuntimeException('Job timed out'));

        $this->assertSame([AiRequest::ERROR, AiRequestLifecycle::MSG_INTERRUPTED], [$pending->fresh()->status, $pending->fresh()->error_message]);
        $this->assertSame(AiRequest::DONE, $done->fresh()->status, 'Um pedido já concluído não muda.');
        $this->assertSame(1, Alert::where('company_id', $this->a->id)->where('title', 'Não foi possível gerar a sugestão do Perfil da Marca')->count());
    }

    // ── Resultado à espera ───────────────────────────────────────────────────

    public function test_latest_request_waits_per_context_until_dismissed(): void
    {
        $profile = $this->request($this->a, ['status' => AiRequest::DONE, 'result' => ['fields' => []]]);
        $this->request($this->a, ['mode' => AiRequest::MODE_IDEAS, 'status' => AiRequest::DONE, 'input' => ['year' => 2026, 'month' => 11], 'result' => ['ideas' => []]]);
        $december = $this->request($this->a, ['mode' => AiRequest::MODE_IDEAS, 'input' => ['year' => 2026, 'month' => 12]]);
        $mineNew = $this->request($this->a, ['mode' => AiRequest::MODE_BLOG, 'user_id' => $this->userA->id]);
        $this->request($this->a, ['mode' => AiRequest::MODE_BLOG, 'user_id' => $this->otherA->id]);
        $old = $this->request($this->a, ['mode' => AiRequest::MODE_CREATIVE, 'editorial_post_id' => null]);
        $old->forceFill(['created_at' => now()->subDays(2)])->save();

        $latest = fn (array $q) => $this->as($this->userA)->getJson($this->url($this->a, '/ai-requests/latest?' . http_build_query($q)))->assertOk()->json('data');

        $this->assertSame($profile->id, $latest(['mode' => 'brand_profile'])['id']);
        $this->assertSame($december->id, $latest(['mode' => 'ideas', 'year' => 2026, 'month' => 12])['id']);
        $this->assertSame(['ideas', 2026, 12], ['ideas', $latest(['mode' => 'ideas', 'year' => 2026, 'month' => 12])['year'], 12]);
        $this->assertNull($latest(['mode' => 'ideas', 'year' => 2027, 'month' => 1]));
        $this->assertSame($mineNew->id, $latest(['mode' => 'blog'])['id'], 'Artigo novo: só os pedidos do próprio utilizador.');
        $this->assertNull($latest(['mode' => 'creative', 'editorial_post_id' => 0]), 'Mais de 24 horas: já não está à espera.');

        $this->as($this->userA)->postJson($this->url($this->a, "/ai-requests/{$profile->id}/dismiss"))->assertOk()->assertJsonPath('data.dismissed', true);
        $this->assertNull($latest(['mode' => 'brand_profile']));
    }

    public function test_tenancy_of_waiting_requests(): void
    {
        $fromB = $this->request($this->b, ['user_id' => $this->userB->id]);

        $this->as($this->userA)->getJson($this->url($this->a, '/ai-requests/latest?mode=brand_profile'))->assertOk()->assertJsonPath('data', null);
        $this->as($this->userA)->getJson($this->url($this->b, '/ai-requests/latest?mode=brand_profile'))->assertStatus(403);
        $this->as($this->userA)->postJson($this->url($this->a, "/ai-requests/{$fromB->id}/dismiss"))->assertStatus(404);
        $this->as($this->userA)->postJson($this->url($this->b, "/ai-requests/{$fromB->id}/dismiss"))->assertStatus(403);
        $this->assertNull($fromB->fresh()->dismissed_at);
    }
}
