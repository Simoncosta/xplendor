<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\QuoteCreatedForCompanyMail;
use App\Mail\QuoteDecisionMail;
use App\Models\Company;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * XPLENDOR — Orçamentos avulsos ligados a uma empresa. Cobre: criar ligado →
 * aparece no painel dela + email; criar com nome livre → NÃO aparece em painel;
 * empresa aprova/rejeita → estado + Simon notificado; empresa não se auto-marca
 * pago; tenant (A não vê B); não-root não acede à /admin/quotes.
 */
class QuoteCompanyLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $root;
    private Company $quebom;
    private User $quebomUser;
    private Company $other;
    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'Test Plan', 'price' => 0, 'car_limit' => 99,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $home = Company::create(['nipc' => '500000900', 'fiscal_name' => 'Root Co', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->root = User::factory()->create(['company_id' => $home->id, 'role' => 'root']);

        $this->quebom = Company::create(['nipc' => '500000901', 'fiscal_name' => 'Quebom Lda', 'trade_name' => 'Quebom', 'email' => 'quebom@example.pt', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->quebomUser = User::factory()->create(['company_id' => $this->quebom->id, 'role' => 'admin']);

        $this->other = Company::create(['nipc' => '500000902', 'fiscal_name' => 'Outra Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->otherUser = User::factory()->create(['company_id' => $this->other->id, 'role' => 'admin']);
    }

    private function standUrl(int $companyId, string $suffix = ''): string
    {
        return "/api/v1/companies/{$companyId}/quotes{$suffix}";
    }

    public function test_root_creates_quote_linked_to_company_notifies_it(): void
    {
        $res = $this->actingAs($this->root, 'sanctum')->postJson('/api/v1/admin/quotes', [
            'company_id' => $this->quebom->id,
            'description' => 'Campanha de outubro',
            'amount' => 300,
        ]);
        $res->assertStatus(200)
            ->assertJsonPath('data.company_id', $this->quebom->id)
            ->assertJsonPath('data.is_linked', true)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.client_name', 'Quebom'); // nome derivado da empresa

        Mail::assertQueued(QuoteCreatedForCompanyMail::class, fn ($m) => $m->hasTo('quebom@example.pt'));
    }

    public function test_free_text_quote_has_no_company_and_no_panel(): void
    {
        $res = $this->actingAs($this->root, 'sanctum')->postJson('/api/v1/admin/quotes', [
            'client_name' => 'Spacedrive',
            'description' => 'Website',
            'amount' => 1500,
        ]);
        $res->assertStatus(200)
            ->assertJsonPath('data.is_linked', false)
            ->assertJsonPath('data.client_name', 'Spacedrive');

        Mail::assertNothingQueued(); // sem empresa → ninguém a notificar

        // Não aparece em painel nenhum (não tem company_id).
        $count = Quote::whereNotNull('company_id')->count();
        $this->assertSame(0, $count);
    }

    public function test_linked_quote_appears_in_company_panel_only(): void
    {
        $q = Quote::create(['company_id' => $this->quebom->id, 'client_name' => 'Quebom', 'description' => 'd', 'amount' => 100, 'status' => 'pending']);

        // Aparece no painel da Quebom.
        $mine = $this->actingAs($this->quebomUser, 'sanctum')->getJson($this->standUrl($this->quebom->id))->json('data');
        $this->assertCount(1, $mine);
        $this->assertSame($q->id, $mine[0]['id']);

        // NÃO aparece no painel de outra empresa (tenant).
        $alien = $this->actingAs($this->otherUser, 'sanctum')->getJson($this->standUrl($this->other->id))->json('data');
        $this->assertCount(0, $alien);
    }

    public function test_company_approves_changes_state_and_notifies_simon(): void
    {
        $q = Quote::create(['company_id' => $this->quebom->id, 'client_name' => 'Quebom', 'description' => 'd', 'amount' => 100, 'status' => 'pending']);
        Mail::fake();

        $res = $this->actingAs($this->quebomUser, 'sanctum')
            ->patchJson($this->standUrl($this->quebom->id, "/{$q->id}/decision"), ['decision' => 'approve']);
        $res->assertStatus(200)->assertJsonPath('data.status', 'approved');

        Mail::assertQueued(QuoteDecisionMail::class, fn ($m) => $m->approved === true && $m->hasTo('simonfrtd@gmail.com'));
    }

    public function test_company_rejects_changes_state(): void
    {
        $q = Quote::create(['company_id' => $this->quebom->id, 'client_name' => 'Quebom', 'description' => 'd', 'amount' => 100, 'status' => 'pending']);

        $this->actingAs($this->quebomUser, 'sanctum')
            ->patchJson($this->standUrl($this->quebom->id, "/{$q->id}/decision"), ['decision' => 'reject'])
            ->assertStatus(200)->assertJsonPath('data.status', 'rejected');
    }

    public function test_company_cannot_decide_other_company_quote(): void
    {
        $q = Quote::create(['company_id' => $this->quebom->id, 'client_name' => 'Quebom', 'description' => 'd', 'amount' => 100, 'status' => 'pending']);

        // Utilizador de outra empresa tenta decidir pela rota da própria empresa → 404 (findScoped).
        $this->actingAs($this->otherUser, 'sanctum')
            ->patchJson($this->standUrl($this->other->id, "/{$q->id}/decision"), ['decision' => 'approve'])
            ->assertStatus(404);
        $this->assertSame('pending', $q->fresh()->status);
    }

    public function test_company_cannot_self_mark_paid(): void
    {
        // Não existe endpoint de stand para pago/concluído — marcar pago é só /admin (root).
        $q = Quote::create(['company_id' => $this->quebom->id, 'client_name' => 'Quebom', 'description' => 'd', 'amount' => 100, 'status' => 'approved']);
        $this->actingAs($this->quebomUser, 'sanctum')
            ->patchJson("/api/v1/admin/quotes/{$q->id}/mark-paid")
            ->assertStatus(403); // não-root não passa o portão /admin
        $this->assertSame('approved', $q->fresh()->status);
    }

    public function test_admin_marks_paid_then_completed(): void
    {
        $q = Quote::create(['company_id' => $this->quebom->id, 'client_name' => 'Quebom', 'description' => 'd', 'amount' => 100, 'status' => 'approved']);

        $this->actingAs($this->root, 'sanctum')->patchJson("/api/v1/admin/quotes/{$q->id}/mark-paid")
            ->assertStatus(200)->assertJsonPath('data.status', 'paid');
        $this->actingAs($this->root, 'sanctum')->patchJson("/api/v1/admin/quotes/{$q->id}/complete")
            ->assertStatus(200)->assertJsonPath('data.status', 'completed');
    }

    public function test_admin_cannot_approve_linked_quote_on_behalf(): void
    {
        $q = Quote::create(['company_id' => $this->quebom->id, 'client_name' => 'Quebom', 'description' => 'd', 'amount' => 100, 'status' => 'pending']);
        $this->actingAs($this->root, 'sanctum')
            ->patchJson("/api/v1/admin/quotes/{$q->id}/status", ['status' => 'approved'])
            ->assertStatus(422);
        $this->assertSame('pending', $q->fresh()->status);
    }

    public function test_non_root_cannot_access_admin_quotes(): void
    {
        $this->actingAs($this->quebomUser, 'sanctum')->getJson('/api/v1/admin/quotes')->assertStatus(403);
        $this->actingAs($this->quebomUser, 'sanctum')->postJson('/api/v1/admin/quotes', [
            'client_name' => 'X', 'description' => 'd', 'amount' => 1,
        ])->assertStatus(403);
    }
}
