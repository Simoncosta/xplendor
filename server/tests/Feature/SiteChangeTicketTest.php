<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\SiteChangeDecisionMail;
use App\Mail\SiteChangeQuotedMail;
use App\Models\Company;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * DMS — Tipo de ticket PAGO "site_change" (Alteração ao site) + camada de
 * orçamento. Cobre o fluxo completo de estados, quem muda o quê (permissões),
 * emails do fluxo, tenant isolation e guardas de transição.
 */
class SiteChangeTicketTest extends TestCase
{
    use RefreshDatabase;

    private Company $compA;
    private Company $compB;
    private User $root;        // Simon (super-admin)
    private User $standUser;   // Matilde (stand da empresa A)
    private User $standB;      // stand da empresa B

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Mail::fake();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'Test Plan', 'price' => 0, 'car_limit' => 99,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->compA = Company::create(['nipc' => '500000600', 'fiscal_name' => 'Empresa A', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->compB = Company::create(['nipc' => '500000601', 'fiscal_name' => 'Empresa B', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->root = User::factory()->create(['company_id' => $this->compA->id, 'role' => 'root']);
        $this->standUser = User::factory()->create(['company_id' => $this->compA->id, 'role' => 'admin']);
        $this->standB = User::factory()->create(['company_id' => $this->compB->id, 'role' => 'admin']);
    }

    private function standUrl(?int $id = null, string $suffix = ''): string
    {
        $base = "/api/v1/companies/{$this->compA->id}/support-tickets";
        return $id ? "{$base}/{$id}{$suffix}" : $base;
    }

    /** Cria um ticket site_change no estado dado, para a empresa A. */
    private function makeTicket(string $quoteStatus = 'awaiting_quote', array $extra = []): SupportTicket
    {
        return SupportTicket::create(array_merge([
            'company_id'   => $this->compA->id,
            'user_id'      => $this->standUser->id,
            'type'         => 'site_change',
            'title'        => 'Mudar banner do site',
            'description'  => 'Trocar o banner principal.',
            'status'       => 'open',
            'quote_status' => $quoteStatus,
        ], $extra));
    }

    public function test_site_change_ticket_starts_awaiting_quote(): void
    {
        $res = $this->actingAs($this->standUser, 'sanctum')->postJson($this->standUrl(), [
            'type' => 'site_change', 'title' => 'Nova página de contactos', 'description' => 'Criar página.',
        ]);
        $res->assertStatus(200)
            ->assertJsonPath('data.type', 'site_change')
            ->assertJsonPath('data.quote_status', 'awaiting_quote')
            ->assertJsonPath('data.status', 'open');
    }

    public function test_admin_sets_quote_computes_amount_and_emails_stand(): void
    {
        $t = $this->makeTicket();
        Mail::fake();

        $res = $this->actingAs($this->root, 'sanctum')->patchJson("/api/v1/admin/tickets/{$t->id}/quote", ['estimated_hours' => 2]);
        $res->assertStatus(200)
            ->assertJsonPath('data.quote_status', 'quoted')
            ->assertJsonPath('data.estimated_hours', 2)
            ->assertJsonPath('data.quoted_amount', 50)   // 2h × 25€
            ->assertJsonPath('data.status', 'in_review');

        Mail::assertQueued(SiteChangeQuotedMail::class, fn ($m) => $m->hasTo($this->standUser->email));
    }

    public function test_stand_approves_advances_and_emails_admin(): void
    {
        $t = $this->makeTicket('quoted', ['estimated_hours' => 2, 'quoted_amount' => 50, 'status' => 'in_review']);
        Mail::fake();

        $res = $this->actingAs($this->standUser, 'sanctum')->patchJson($this->standUrl($t->id, '/quote-decision'), ['decision' => 'approve']);
        $res->assertStatus(200)->assertJsonPath('data.quote_status', 'approved');

        Mail::assertQueued(SiteChangeDecisionMail::class, fn ($m) => $m->approved === true && $m->hasTo('simonfrtd@gmail.com'));
    }

    public function test_stand_rejects_closes_and_emails_admin(): void
    {
        $t = $this->makeTicket('quoted', ['estimated_hours' => 2, 'quoted_amount' => 50, 'status' => 'in_review']);
        Mail::fake();

        $res = $this->actingAs($this->standUser, 'sanctum')->patchJson($this->standUrl($t->id, '/quote-decision'), ['decision' => 'reject']);
        $res->assertStatus(200)
            ->assertJsonPath('data.quote_status', 'rejected')
            ->assertJsonPath('data.status', 'closed');   // fecha, sem renegociar

        Mail::assertQueued(SiteChangeDecisionMail::class, fn ($m) => $m->approved === false && $m->hasTo('simonfrtd@gmail.com'));
    }

    public function test_admin_marks_paid_with_invoice_pdf(): void
    {
        $t = $this->makeTicket('approved', ['estimated_hours' => 2, 'quoted_amount' => 50, 'status' => 'in_review']);

        $res = $this->actingAs($this->root, 'sanctum')->post("/api/v1/admin/tickets/{$t->id}/mark-paid", [
            'invoice' => UploadedFile::fake()->create('fatura.pdf', 40, 'application/pdf'),
        ], ['Accept' => 'application/json']);
        $res->assertStatus(200)->assertJsonPath('data.quote_status', 'paid');

        $fresh = $t->fresh();
        $this->assertNotNull($fresh->invoice_path);
        $this->assertStringEndsWith('.pdf', $fresh->invoice_path);
        Storage::disk('public')->assertExists(ltrim(str_replace('/storage', '', $fresh->invoice_path), '/'));
    }

    public function test_mark_paid_rejects_non_pdf(): void
    {
        $t = $this->makeTicket('approved', ['quoted_amount' => 50]);
        $this->actingAs($this->root, 'sanctum')->post("/api/v1/admin/tickets/{$t->id}/mark-paid", [
            'invoice' => UploadedFile::fake()->create('mal.txt', 10, 'text/plain'),
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_admin_marks_completed_sets_resolved(): void
    {
        $t = $this->makeTicket('paid', ['estimated_hours' => 2, 'quoted_amount' => 50, 'status' => 'in_review']);

        $res = $this->actingAs($this->root, 'sanctum')->patchJson("/api/v1/admin/tickets/{$t->id}/complete");
        $res->assertStatus(200)
            ->assertJsonPath('data.quote_status', 'completed')
            ->assertJsonPath('data.status', 'resolved');
        $this->assertNotNull($t->fresh()->resolved_at);
    }

    public function test_stand_cannot_use_admin_quote_endpoints(): void
    {
        $t = $this->makeTicket();
        // Stand (não-root) não passa o portão /admin (ensure_super_admin → 403).
        $this->actingAs($this->standUser, 'sanctum')->patchJson("/api/v1/admin/tickets/{$t->id}/quote", ['estimated_hours' => 2])->assertStatus(403);
        $this->actingAs($this->standUser, 'sanctum')->post("/api/v1/admin/tickets/{$t->id}/mark-paid", [], ['Accept' => 'application/json'])->assertStatus(403);
        $this->actingAs($this->standUser, 'sanctum')->patchJson("/api/v1/admin/tickets/{$t->id}/complete")->assertStatus(403);
    }

    public function test_transition_guards_return_422(): void
    {
        // Aprovar antes de haver orçamento (awaiting_quote) → 422.
        $t1 = $this->makeTicket('awaiting_quote');
        $this->actingAs($this->standUser, 'sanctum')->patchJson($this->standUrl($t1->id, '/quote-decision'), ['decision' => 'approve'])->assertStatus(422);

        // Marcar pago sem estar aprovado → 422.
        $t2 = $this->makeTicket('quoted', ['quoted_amount' => 50]);
        $this->actingAs($this->root, 'sanctum')->post("/api/v1/admin/tickets/{$t2->id}/mark-paid", [], ['Accept' => 'application/json'])->assertStatus(422);

        // Concluir sem estar pago → 422.
        $t3 = $this->makeTicket('approved', ['quoted_amount' => 50]);
        $this->actingAs($this->root, 'sanctum')->patchJson("/api/v1/admin/tickets/{$t3->id}/complete")->assertStatus(422);
    }

    public function test_quote_actions_reject_non_site_change_type(): void
    {
        $idea = SupportTicket::create([
            'company_id' => $this->compA->id, 'user_id' => $this->standUser->id,
            'type' => 'idea', 'title' => 'Grátis', 'description' => 'd', 'status' => 'open',
        ]);
        // Orçar um ticket grátis → 422 (assertSiteChange).
        $this->actingAs($this->root, 'sanctum')->patchJson("/api/v1/admin/tickets/{$idea->id}/quote", ['estimated_hours' => 2])->assertStatus(422);
    }

    public function test_tenant_isolation_stand_cannot_decide_other_company_ticket(): void
    {
        $t = $this->makeTicket('quoted', ['quoted_amount' => 50]); // empresa A
        // Stand da empresa B tenta decidir pela rota da empresa B → 404 (findScoped).
        $this->actingAs($this->standB, 'sanctum')
            ->patchJson("/api/v1/companies/{$this->compB->id}/support-tickets/{$t->id}/quote-decision", ['decision' => 'approve'])
            ->assertStatus(404);
        $this->assertSame('quoted', $t->fresh()->quote_status);
    }
}
