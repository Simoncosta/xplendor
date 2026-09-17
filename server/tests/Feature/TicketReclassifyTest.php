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
 * Reclassificação de TIPO de ticket (admin) + ligação à camada de orçamento:
 *  · → site_change ativa o quote_status;
 *  · site_change → outro sem orçamento limpa o quote_status (sem órfãos);
 *  · site_change → outro COM orçamento é BLOQUEADO (não apaga dados);
 *  · só root reclassifica.
 */
class TicketReclassifyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
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
        $this->company = Company::create(['nipc' => '500000650', 'fiscal_name' => 'Empresa A', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->root = User::factory()->create(['company_id' => $this->company->id, 'role' => 'root']);
        $this->standAdmin = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
    }

    private function ticket(array $extra = []): SupportTicket
    {
        return SupportTicket::create(array_merge([
            'company_id' => $this->company->id, 'user_id' => $this->standAdmin->id,
            'type' => 'bug', 'title' => 'T', 'description' => 'd', 'status' => 'open',
        ], $extra));
    }

    private function reclassify(int $id, string $type)
    {
        return $this->actingAs($this->root, 'sanctum')
            ->patchJson("/api/v1/admin/tickets/{$id}/type", ['type' => $type]);
    }

    public function test_reclassify_bug_to_site_change_activates_quote_layer(): void
    {
        $t = $this->ticket(['type' => 'bug', 'quote_status' => null]);

        $this->reclassify($t->id, 'site_change')
            ->assertStatus(200)
            ->assertJsonPath('data.type', 'site_change')
            ->assertJsonPath('data.quote_status', 'awaiting_quote'); // camada ativada

        $this->assertSame('awaiting_quote', $t->fresh()->quote_status);
    }

    public function test_reclassify_site_change_without_quote_to_bug_clears_layer(): void
    {
        // site_change ainda em 'awaiting_quote' (sem valor/fatura) → seguro remover.
        $t = $this->ticket(['type' => 'site_change', 'quote_status' => 'awaiting_quote']);

        $this->reclassify($t->id, 'bug')
            ->assertStatus(200)
            ->assertJsonPath('data.type', 'bug')
            ->assertJsonPath('data.quote_status', null); // camada desativada, sem órfãos

        $this->assertNull($t->fresh()->quote_status);
    }

    public function test_reclassify_site_change_with_quote_is_blocked(): void
    {
        // Já há orçamento (valor definido) → NÃO apagar dados; bloquear (422).
        $t = $this->ticket([
            'type' => 'site_change', 'quote_status' => 'quoted',
            'estimated_hours' => 2, 'quoted_amount' => 50, 'status' => 'in_review',
        ]);

        $this->reclassify($t->id, 'bug')->assertStatus(422);

        // Nada mudou — dados de orçamento intactos.
        $fresh = $t->fresh();
        $this->assertSame('site_change', $fresh->type);
        $this->assertSame('quoted', $fresh->quote_status);
        $this->assertNotNull($fresh->quoted_amount);
    }

    public function test_reclassify_between_free_types_just_changes_type(): void
    {
        $t = $this->ticket(['type' => 'bug']);
        $this->reclassify($t->id, 'improvement')
            ->assertStatus(200)
            ->assertJsonPath('data.type', 'improvement')
            ->assertJsonPath('data.quote_status', null);
    }

    public function test_invalid_type_rejected(): void
    {
        $t = $this->ticket();
        $this->reclassify($t->id, 'nonsense')->assertStatus(422);
    }

    public function test_non_root_cannot_reclassify(): void
    {
        $t = $this->ticket();
        $this->actingAs($this->standAdmin, 'sanctum')
            ->patchJson("/api/v1/admin/tickets/{$t->id}/type", ['type' => 'site_change'])
            ->assertStatus(403);
        $this->assertSame('bug', $t->fresh()->type);
    }
}
