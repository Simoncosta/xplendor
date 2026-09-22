<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * XPLENDOR — Seleção/soma/pipeline de orçamentos-em-tickets (site_change), dois lados:
 *  · ADMIN: pipeline por quote_status (contagem/valor/horas, SEM IVA), filtro empresa.
 *  · STAND: vê SÓ os seus, aprova pacote (só 'quoted'→'approved'), tenancy.
 */
class TicketQuotePipelineTest extends TestCase
{
    use RefreshDatabase;

    private Company $compA;
    private Company $compB;
    private User $root;
    private User $standA;
    private User $standB;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->compA = Company::create(['nipc' => '500000700', 'fiscal_name' => 'Empresa A', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->compB = Company::create(['nipc' => '500000701', 'fiscal_name' => 'Empresa B', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->root = User::factory()->create(['company_id' => $this->compA->id, 'role' => 'root']);
        $this->standA = User::factory()->create(['company_id' => $this->compA->id, 'role' => 'admin']);
        $this->standB = User::factory()->create(['company_id' => $this->compB->id, 'role' => 'admin']);
    }

    private function ticket(Company $c, string $quoteStatus, float $amount, float $hours, string $title = 'Alteração'): SupportTicket
    {
        return SupportTicket::create([
            'company_id' => $c->id, 'user_id' => $this->standA->id, 'type' => 'site_change',
            'title' => $title, 'description' => 'd', 'status' => 'in_review',
            'quote_status' => $quoteStatus, 'estimated_hours' => $hours, 'quoted_amount' => $amount,
        ]);
    }

    public function test_admin_pipeline_sums_by_quote_status_without_iva(): void
    {
        $this->ticket($this->compA, 'quoted', 50, 2);
        $this->ticket($this->compA, 'quoted', 100, 4);
        $this->ticket($this->compA, 'approved', 200, 8);
        $this->ticket($this->compB, 'quoted', 999, 40); // outra empresa

        $res = $this->actingAs($this->root, 'sanctum')->getJson('/api/v1/admin/tickets/quote-pipeline')
            ->assertStatus(200)
            ->assertJsonPath('data.by_status.quoted.count', 3)      // 2 A + 1 B
            ->assertJsonPath('data.by_status.quoted.amount', 1149)  // 50+100+999
            ->assertJsonPath('data.by_status.approved.amount', 200);
        // total = tudo (sem IVA, soma tal como inserido)
        $this->assertSame(1349.0, (float) $res->json('data.total.amount'));
        $this->assertSame(54.0, (float) $res->json('data.total.hours'));
    }

    public function test_admin_pipeline_filters_by_company(): void
    {
        $this->ticket($this->compA, 'quoted', 50, 2);
        $this->ticket($this->compB, 'quoted', 999, 40);

        $this->actingAs($this->root, 'sanctum')
            ->getJson("/api/v1/admin/tickets/quote-pipeline?company_id={$this->compA->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.by_status.quoted.amount', 50)   // só a empresa A
            ->assertJsonPath('data.total.amount', 50);
    }

    public function test_stand_sees_only_own_site_change_quotes(): void
    {
        $this->ticket($this->compA, 'quoted', 50, 2, 'Da A');
        $this->ticket($this->compB, 'quoted', 999, 40, 'Da B');
        // um ticket grátis não deve aparecer nos orçamentos
        SupportTicket::create(['company_id' => $this->compA->id, 'user_id' => $this->standA->id, 'type' => 'idea', 'title' => 'Grátis', 'description' => 'd', 'status' => 'open']);

        $res = $this->actingAs($this->standA, 'sanctum')->getJson("/api/v1/companies/{$this->compA->id}/support-tickets/quotes")
            ->assertStatus(200);
        $titles = collect($res->json('data.tickets'))->pluck('title')->all();
        $this->assertSame(['Da A'], $titles);                       // só site_change com orçamento, só da A
        $this->assertSame(50.0, (float) $res->json('data.summary.by_status.quoted.amount'));
    }

    public function test_stand_approves_package_only_eligible(): void
    {
        $t1 = $this->ticket($this->compA, 'quoted', 50, 2);
        $t2 = $this->ticket($this->compA, 'quoted', 100, 4);
        $t3 = $this->ticket($this->compA, 'approved', 200, 8); // já aprovado — NÃO re-aprova

        $res = $this->actingAs($this->standA, 'sanctum')->postJson(
            "/api/v1/companies/{$this->compA->id}/support-tickets/quotes/approve",
            ['ids' => [$t1->id, $t2->id, $t3->id]]
        )->assertStatus(200);

        $this->assertEqualsCanonicalizing([$t1->id, $t2->id], $res->json('data.approved'));
        $this->assertSame('approved', $t1->fresh()->quote_status);
        $this->assertSame('approved', $t2->fresh()->quote_status);
        $this->assertSame('approved', $t3->fresh()->quote_status);   // continua approved (não mudou)
        // o t3 aparece nos skipped (não estava "por aprovar")
        $skippedIds = collect($res->json('data.skipped'))->pluck('id')->all();
        $this->assertContains($t3->id, $skippedIds);
    }

    public function test_stand_cannot_approve_other_company_tickets(): void
    {
        $tB = $this->ticket($this->compB, 'quoted', 999, 40);
        // Stand A tenta aprovar um ticket da empresa B (via rota da empresa A) → ignorado (tenancy).
        $res = $this->actingAs($this->standA, 'sanctum')->postJson(
            "/api/v1/companies/{$this->compA->id}/support-tickets/quotes/approve",
            ['ids' => [$tB->id]]
        )->assertStatus(200);

        $this->assertSame([], $res->json('data.approved'));
        $this->assertSame('quoted', $tB->fresh()->quote_status);     // intacto
    }

    public function test_stand_pipeline_route_is_not_captured_as_ticket_id(): void
    {
        // Garante que /support-tickets/quotes não é apanhado por /{ticket} (ordem das rotas).
        $this->actingAs($this->standA, 'sanctum')
            ->getJson("/api/v1/companies/{$this->compA->id}/support-tickets/quotes")
            ->assertStatus(200)->assertJsonStructure(['data' => ['tickets', 'summary']]);
    }

    public function test_admin_pipeline_gated_to_root(): void
    {
        $this->actingAs($this->standA, 'sanctum')->getJson('/api/v1/admin/tickets/quote-pipeline')->assertStatus(403);
    }
}
