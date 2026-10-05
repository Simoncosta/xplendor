<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ImpersonationSession;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\SupportTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Mudança de TIPO de ticket pela equipa XPLENDOR, com as regras do orçamento:
 * cada estado do orçamento, bloqueio, histórico (support_ticket_type_changes),
 * mensagens ao cliente, root vs cliente vs impersonation, tenancy e concorrência
 * com a aprovação pelo cliente. Inclui o efeito no pipeline do dashboard root.
 */
class TicketTypeChangeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Company $otherCompany;
    private User $root;
    private User $client;
    private User $otherClient;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500015001', 'fiscal_name' => 'Stand A', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->otherCompany = Company::create(['nipc' => '500015002', 'fiscal_name' => 'Stand B', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->root = User::factory()->create(['company_id' => $this->company->id, 'role' => 'root', 'name' => 'Equipa XPLENDOR']);
        $this->client = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
        $this->otherClient = User::factory()->create(['company_id' => $this->otherCompany->id, 'role' => 'admin']);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function ticket(string $type = 'site_change', ?string $quoteStatus = 'awaiting_quote', array $extra = []): SupportTicket
    {
        return SupportTicket::create(array_merge([
            'company_id' => $this->company->id, 'user_id' => $this->client->id,
            'type' => $type, 'title' => 'Pedido', 'description' => 'd', 'status' => 'open',
            'quote_status' => $type === 'site_change' ? $quoteStatus : null,
        ], $extra));
    }

    private function quoted(string $quoteStatus = 'quoted', array $extra = []): SupportTicket
    {
        return $this->ticket('site_change', $quoteStatus, array_merge(['estimated_hours' => 2, 'quoted_amount' => 50, 'status' => 'in_review'], $extra));
    }

    private function change(SupportTicket $t, string $type, ?bool $confirm = null, ?User $as = null)
    {
        $body = ['type' => $type] + ($confirm === null ? [] : ['confirm_reset' => $confirm]);

        return $this->actingAs($as ?? $this->root, 'sanctum')->patchJson("/api/v1/admin/tickets/{$t->id}/type", $body);
    }

    private function messages(SupportTicket $t)
    {
        return DB::table('support_ticket_messages')->where('support_ticket_id', $t->id)->orderBy('id')->get();
    }

    // ── 1. Sem orçamento ou a aguardar: mudança livre ────────────────────────

    public function test_awaiting_quote_changes_freely_and_is_recorded(): void
    {
        $t = $this->ticket('site_change', 'awaiting_quote');

        $this->change($t, 'improvement')->assertOk()
            ->assertJsonPath('data.type', 'improvement')
            ->assertJsonPath('data.quote_status', null)
            ->assertJsonPath('data.type_changes.0.from_type', 'site_change')
            ->assertJsonPath('data.type_changes.0.previous_quote_status', 'awaiting_quote')
            ->assertJsonPath('data.type_changes.0.changed_by_name', 'Equipa XPLENDOR');

        $this->assertCount(0, $this->messages($t));   // sem orçamento anulado, sem mensagem
    }

    public function test_between_free_types_records_history_without_message(): void
    {
        $t = $this->ticket('bug', null);

        $this->change($t, 'idea')->assertOk()->assertJsonPath('data.type', 'idea');

        $this->assertSame(1, DB::table('support_ticket_type_changes')->where('support_ticket_id', $t->id)->count());
        $this->assertCount(0, $this->messages($t));
    }

    // ── 2. Orçado ou rejeitado: confirmação, valores a null, rasto ───────────

    public function test_quoted_without_confirmation_returns_409_with_the_quote_and_changes_nothing(): void
    {
        $t = $this->quoted();

        $this->change($t, 'bug')->assertStatus(409)
            ->assertJsonPath('errors.confirmation_required', true)
            ->assertJsonPath('errors.quote_status', 'quoted')
            ->assertJsonPath('errors.quoted_amount', 50)
            ->assertJsonPath('errors.estimated_hours', 2);

        $fresh = $t->fresh();
        $this->assertSame(['site_change', 'quoted', '50.00'], [$fresh->type, $fresh->quote_status, $fresh->quoted_amount]);
        $this->assertSame(0, DB::table('support_ticket_type_changes')->count());
    }

    public function test_quoted_with_confirmation_resets_quote_keeps_trail_and_tells_the_client(): void
    {
        $t = $this->quoted();

        $this->change($t, 'bug', true)->assertOk()
            ->assertJsonPath('data.type', 'bug')
            ->assertJsonPath('data.quote_status', null)
            ->assertJsonPath('data.quoted_amount', null)
            ->assertJsonPath('data.estimated_hours', null)
            ->assertJsonPath('data.type_changes.0.previous_quote_status', 'quoted')
            ->assertJsonPath('data.type_changes.0.previous_quoted_amount', 50)
            ->assertJsonPath('data.type_changes.0.previous_estimated_hours', 2);

        $this->assertSame('in_review', $t->fresh()->status);   // o estado do ticket não muda
        $message = $this->messages($t)->sole();
        $this->assertTrue((bool) $message->is_staff);
        $this->assertSame($this->root->id, $message->user_id);
        $this->assertSame('A equipa XPLENDOR alterou o tipo deste pedido de «Alteração ao site» para «Bug». O orçamento anterior (50,00 €, 2 h) foi anulado e deixou de estar pendente de aprovação.', $message->body);
        $this->assertStringNotContainsString('—', $message->body);
    }

    public function test_rejected_is_treated_like_quoted(): void
    {
        $t = $this->quoted('rejected', ['status' => 'closed', 'estimated_hours' => 1.5, 'quoted_amount' => 37.5]);

        $this->change($t, 'suggestion')->assertStatus(409)->assertJsonPath('errors.quote_status', 'rejected');
        $this->change($t, 'suggestion', true)->assertOk()->assertJsonPath('data.quote_status', null);

        $this->assertSame('A equipa XPLENDOR alterou o tipo deste pedido de «Alteração ao site» para «Sugestão». O orçamento anterior (37,50 €, 1,5 h) foi anulado.', $this->messages($t)->sole()->body);
        $this->assertSame('rejected', DB::table('support_ticket_type_changes')->where('support_ticket_id', $t->id)->value('previous_quote_status'));
    }

    // ── 3. Aprovado, pago ou concluído: bloqueado ────────────────────────────

    public function test_approved_paid_and_completed_are_blocked_even_with_confirmation(): void
    {
        foreach (['approved', 'paid', 'completed'] as $status) {
            $t = $this->quoted($status);

            $this->change($t, 'bug', true)->assertStatus(422)
                ->assertJsonFragment(['Este pedido tem um orçamento aprovado, pago ou concluído, por isso o tipo não pode ser alterado. Anule primeiro o orçamento.']);

            $fresh = $t->fresh();
            $this->assertSame(['site_change', $status, '50.00'], [$fresh->type, $fresh->quote_status, $fresh->quoted_amount], $status);
        }
        $this->assertSame(0, DB::table('support_ticket_type_changes')->count());
        $this->assertSame(0, DB::table('support_ticket_messages')->count());
    }

    // ── 4. Para site_change: a aguardar orçamento + aviso de pedido pago ─────

    public function test_free_to_site_change_awaits_quote_and_warns_the_client_it_is_paid(): void
    {
        $t = $this->ticket('idea', null);

        $this->change($t, 'site_change')->assertOk()->assertJsonPath('data.quote_status', 'awaiting_quote');

        $this->assertSame('A equipa XPLENDOR alterou o tipo deste pedido de «Ideia» para «Alteração ao site». Este tipo de pedido é pago: vai receber um orçamento antes de qualquer custo e o trabalho só avança depois da sua aprovação.', $this->messages($t)->sole()->body);
    }

    // ── 5. Quem pode: root sim; cliente, impersonation e outra empresa não ───

    public function test_client_cannot_change_type(): void
    {
        $t = $this->quoted();

        $this->change($t, 'bug', true, $this->client)->assertStatus(403);
        $this->assertSame('site_change', $t->fresh()->type);
    }

    public function test_root_impersonating_a_client_cannot_change_type(): void
    {
        $t = $this->quoted();
        $nt = $this->client->createToken('impersonation', ['impersonation'], now()->addMinutes(30));
        ImpersonationSession::create([
            'root_id' => $this->root->id, 'target_user_id' => $this->client->id, 'company_id' => $this->company->id,
            'token_id' => $nt->accessToken->getKey(), 'ip' => '127.0.0.1', 'user_agent' => 'test', 'started_at' => now(),
        ]);

        $this->patchJson("/api/v1/admin/tickets/{$t->id}/type", ['type' => 'bug', 'confirm_reset' => true], ['Authorization' => 'Bearer ' . $nt->plainTextToken])
            ->assertStatus(403);
        $this->assertSame(['site_change', 'quoted'], [$t->fresh()->type, $t->fresh()->quote_status]);
    }

    public function test_client_sees_the_message_but_not_the_internal_history_and_other_company_sees_nothing(): void
    {
        $t = $this->quoted();
        $this->change($t, 'bug', true)->assertOk();

        $res = $this->actingAs($this->client, 'sanctum')->getJson("/api/v1/companies/{$this->company->id}/support-tickets/{$t->id}")->assertOk();
        $this->assertStringContainsString('foi anulado', $res->json('data.messages.0.body'));
        $this->assertArrayNotHasKey('type_changes', $res->json('data'));

        $this->actingAs($this->otherClient, 'sanctum')->getJson("/api/v1/companies/{$this->company->id}/support-tickets/{$t->id}")->assertStatus(403);
        $this->actingAs($this->otherClient, 'sanctum')->getJson("/api/v1/companies/{$this->otherCompany->id}/support-tickets/{$t->id}")->assertStatus(404);
    }

    public function test_admin_detail_shows_the_history(): void
    {
        $t = $this->quoted();
        $this->change($t, 'bug', true)->assertOk();
        $this->change($t, 'site_change')->assertOk();

        $this->actingAs($this->root, 'sanctum')->getJson("/api/v1/admin/tickets/{$t->id}")->assertOk()
            ->assertJsonCount(2, 'data.type_changes')
            ->assertJsonPath('data.type_changes.0.to_type', 'site_change')     // mais recente primeiro
            ->assertJsonPath('data.type_changes.1.previous_quoted_amount', 50);
    }

    // ── 6. Concorrência com a aprovação pelo cliente ─────────────────────────

    public function test_client_approval_after_reset_fails_with_a_stale_ticket(): void
    {
        $t = $this->quoted();
        $staleSeenByClient = SupportTicket::find($t->id);   // o cliente carregou o pedido ainda orçado

        $this->change($t, 'bug', true)->assertOk();

        try {
            app(SupportTicketService::class)->approveQuote($staleSeenByClient);
            $this->fail('A aprovação devia falhar.');
        } catch (ValidationException) {
        }
        $this->assertSame(['bug', null], [$t->fresh()->type, $t->fresh()->quote_status]);
    }

    public function test_type_change_after_approval_is_blocked_with_a_stale_ticket(): void
    {
        $t = $this->quoted();
        $staleSeenByRoot = SupportTicket::find($t->id);
        app(SupportTicketService::class)->approveQuote($t);   // o cliente aprova primeiro

        try {
            app(SupportTicketService::class)->reclassifyType($staleSeenByRoot, 'bug', $this->root, true);
            $this->fail('A mudança devia ser bloqueada.');
        } catch (ValidationException) {
        }
        $this->assertSame(['site_change', 'approved', '50.00'], [$t->fresh()->type, $t->fresh()->quote_status, $t->fresh()->quoted_amount]);
    }

    public function test_package_approval_skips_a_ticket_whose_quote_was_reset(): void
    {
        $kept = $this->quoted();
        $reset = $this->quoted();
        $this->change($reset, 'bug', true)->assertOk();

        $this->actingAs($this->client, 'sanctum')
            ->postJson("/api/v1/companies/{$this->company->id}/support-tickets/quotes/approve", ['ids' => [$kept->id, $reset->id]])
            ->assertOk();

        $this->assertSame('approved', $kept->fresh()->quote_status);
        $this->assertNull($reset->fresh()->quote_status);
    }

    // ── 7. Dashboard root: o pipeline muda como deve ─────────────────────────

    public function test_pipeline_reflects_the_reset_and_the_new_paid_request(): void
    {
        $quoted = $this->quoted();
        $this->quoted('approved', ['quoted_amount' => 100, 'estimated_hours' => 4]);
        $free = $this->ticket('bug', null);
        $pipeline = fn () => $this->actingAs($this->root, 'sanctum')->getJson('/api/v1/admin/tickets/quote-pipeline')->assertOk()->json('data');

        $before = $pipeline();
        $this->assertSame([1, 50], [$before['by_status']['quoted']['count'], (int) $before['by_status']['quoted']['amount']]);

        $this->change($quoted, 'bug', true)->assertOk();
        $this->change($free, 'site_change')->assertOk();

        $after = $pipeline();
        $this->assertSame([0, 0], [$after['by_status']['quoted']['count'], (int) $after['by_status']['quoted']['amount']]);
        $this->assertSame([1, 0], [$after['by_status']['awaiting_quote']['count'], (int) $after['by_status']['awaiting_quote']['amount']]);
        $this->assertSame([1, 100], [$after['by_status']['approved']['count'], (int) $after['by_status']['approved']['amount']]);   // aprovado intocado
        $this->assertSame(100, (int) $after['total']['amount']);
    }
}
