<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\SupportTicketMessageMail;
use App\Models\Company;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * DMS — Tickets de suporte, LADO ADMIN (transversal, só root).
 * Cobre o acesso transversal (várias empresas), o bloqueio de não-root,
 * mudar estado (resolved_at), responder (is_staff, sem email) e as contagens.
 */
class AdminSupportTicketTest extends TestCase
{
    use RefreshDatabase;

    private Company $compA;
    private Company $compB;
    private User $root;
    private User $standAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'Test Plan', 'price' => 0, 'car_limit' => 99,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->compA = Company::create(['nipc' => '500000500', 'fiscal_name' => 'Empresa A', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->compB = Company::create(['nipc' => '500000501', 'fiscal_name' => 'Empresa B', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->root = User::factory()->create(['company_id' => $this->compA->id, 'role' => 'root']);
        $this->standAdmin = User::factory()->create(['company_id' => $this->compA->id, 'role' => 'admin']);

        $ua = User::factory()->create(['company_id' => $this->compA->id, 'role' => 'admin']);
        $ub = User::factory()->create(['company_id' => $this->compB->id, 'role' => 'admin']);
        SupportTicket::create(['company_id' => $this->compA->id, 'user_id' => $ua->id, 'type' => 'idea', 'title' => 'Ticket A', 'description' => 'd', 'status' => 'open']);
        SupportTicket::create(['company_id' => $this->compB->id, 'user_id' => $ub->id, 'type' => 'bug', 'title' => 'Ticket B', 'description' => 'd', 'status' => 'resolved']);
        SupportTicket::create(['company_id' => $this->compB->id, 'user_id' => $ub->id, 'type' => 'suggestion', 'title' => 'Ticket B2', 'description' => 'd', 'status' => 'in_review']);
    }

    public function test_root_sees_tickets_from_all_companies(): void
    {
        $res = $this->actingAs($this->root, 'sanctum')->getJson('/api/v1/admin/tickets');
        $res->assertStatus(200);
        $titles = collect($res->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Ticket A'));
        $this->assertTrue($titles->contains('Ticket B'));
        $this->assertTrue($titles->contains('Ticket B2'));
        // company_name emitido no lado admin.
        $this->assertNotNull(collect($res->json('data'))->firstWhere('title', 'Ticket A')['company_name']);
    }

    public function test_ordering_pending_first(): void
    {
        $data = $this->actingAs($this->root, 'sanctum')->getJson('/api/v1/admin/tickets')->json('data');
        // Os por-tratar (open/in_review) vêm antes dos resolved/closed.
        $lastStatus = collect($data)->pluck('status');
        $firstResolvedIdx = $lastStatus->search('resolved');
        $lastPendingIdx = max($lastStatus->search('open'), $lastStatus->search('in_review'));
        $this->assertTrue($lastPendingIdx < $firstResolvedIdx);
    }

    public function test_filter_by_company_and_status_and_type(): void
    {
        $r1 = $this->actingAs($this->root, 'sanctum')->getJson("/api/v1/admin/tickets?company_id={$this->compB->id}")->json('data');
        $this->assertCount(2, $r1);
        $r2 = $this->actingAs($this->root, 'sanctum')->getJson('/api/v1/admin/tickets?status=open')->json('data');
        $this->assertCount(1, $r2);
        $r3 = $this->actingAs($this->root, 'sanctum')->getJson('/api/v1/admin/tickets?type=bug')->json('data');
        $this->assertCount(1, $r3);
    }

    public function test_summary_counts(): void
    {
        $res = $this->actingAs($this->root, 'sanctum')->getJson('/api/v1/admin/tickets/summary');
        $res->assertStatus(200)
            ->assertJsonPath('data.open', 1)
            ->assertJsonPath('data.in_review', 1)
            ->assertJsonPath('data.pending', 2)
            ->assertJsonPath('data.resolved', 1)
            ->assertJsonPath('data.total', 3);
    }

    public function test_non_root_forbidden_everywhere(): void
    {
        $t = SupportTicket::first();
        $this->actingAs($this->standAdmin, 'sanctum')->getJson('/api/v1/admin/tickets')->assertStatus(403);
        $this->actingAs($this->standAdmin, 'sanctum')->getJson('/api/v1/admin/tickets/summary')->assertStatus(403);
        $this->actingAs($this->standAdmin, 'sanctum')->getJson("/api/v1/admin/tickets/{$t->id}")->assertStatus(403);
        $this->actingAs($this->standAdmin, 'sanctum')->patchJson("/api/v1/admin/tickets/{$t->id}/status", ['status' => 'closed'])->assertStatus(403);
        $this->actingAs($this->standAdmin, 'sanctum')->postJson("/api/v1/admin/tickets/{$t->id}/messages", ['body' => 'x'])->assertStatus(403);
    }

    public function test_update_status_sets_resolved_at(): void
    {
        $t = SupportTicket::where('title', 'Ticket A')->first();
        $res = $this->actingAs($this->root, 'sanctum')->patchJson("/api/v1/admin/tickets/{$t->id}/status", ['status' => 'resolved']);
        $res->assertStatus(200)->assertJsonPath('data.status', 'resolved');
        $this->assertNotNull($t->fresh()->resolved_at);

        // Voltar a in_review limpa o resolved_at.
        $this->actingAs($this->root, 'sanctum')->patchJson("/api/v1/admin/tickets/{$t->id}/status", ['status' => 'in_review'])->assertStatus(200);
        $this->assertNull($t->fresh()->resolved_at);
    }

    public function test_admin_reply_is_staff_and_sends_no_email(): void
    {
        Mail::fake();
        $t = SupportTicket::where('title', 'Ticket A')->first();
        $res = $this->actingAs($this->root, 'sanctum')->postJson("/api/v1/admin/tickets/{$t->id}/messages", ['body' => 'Estamos a tratar.']);
        $res->assertStatus(200);
        $msgs = $res->json('data.messages');
        $this->assertSame('Estamos a tratar.', end($msgs)['body']);
        $this->assertTrue(end($msgs)['is_staff']);   // resposta do staff
        Mail::assertNothingQueued();                  // não notifica o próprio Simon
    }
}
