<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XPLENDOR — Orçamentos avulsos (gestão comercial, /admin, só root).
 * Cobre CRUD, mudança de estado, validação e o bloqueio de não-root
 * (é transversal; os stands não têm acesso nenhum a orçamentos).
 */
class AdminQuoteTest extends TestCase
{
    use RefreshDatabase;

    private User $root;
    private User $standAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'Test Plan', 'price' => 0, 'car_limit' => 99,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $company = Company::create(['nipc' => '500000700', 'fiscal_name' => 'Empresa A', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->root = User::factory()->create(['company_id' => $company->id, 'role' => 'root']);
        $this->standAdmin = User::factory()->create(['company_id' => $company->id, 'role' => 'admin']);
    }

    public function test_root_creates_quote_defaults_to_pending(): void
    {
        $res = $this->actingAs($this->root, 'sanctum')->postJson('/api/v1/admin/quotes', [
            'client_name'    => 'Spacedrive',
            'client_contact' => 'geral@spacedrive.pt',
            'description'    => 'Tráfego pago 3 meses',
            'amount'         => 1500,
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.client_name', 'Spacedrive')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.amount', 1500);

        $this->assertDatabaseHas('quotes', ['client_name' => 'Spacedrive', 'status' => 'pending']);
    }

    public function test_validation_requires_core_fields(): void
    {
        $this->actingAs($this->root, 'sanctum')->postJson('/api/v1/admin/quotes', [
            'client_contact' => 'x',
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['client_name', 'description', 'amount']);
    }

    public function test_root_lists_quotes_and_filters_by_status(): void
    {
        Quote::create(['client_name' => 'A', 'description' => 'd', 'amount' => 100, 'status' => 'pending']);
        Quote::create(['client_name' => 'B', 'description' => 'd', 'amount' => 200, 'status' => 'approved']);

        $all = $this->actingAs($this->root, 'sanctum')->getJson('/api/v1/admin/quotes')->json('data');
        $this->assertCount(2, $all);

        $approved = $this->actingAs($this->root, 'sanctum')->getJson('/api/v1/admin/quotes?status=approved')->json('data');
        $this->assertCount(1, $approved);
        $this->assertSame('B', $approved[0]['client_name']);
    }

    public function test_root_updates_quote(): void
    {
        $q = Quote::create(['client_name' => 'A', 'description' => 'd', 'amount' => 100, 'status' => 'pending']);

        $res = $this->actingAs($this->root, 'sanctum')->patchJson("/api/v1/admin/quotes/{$q->id}", [
            'amount' => 250, 'notes' => 'Revisto após call.',
        ]);
        $res->assertStatus(200)
            ->assertJsonPath('data.amount', 250)
            ->assertJsonPath('data.notes', 'Revisto após call.');
    }

    public function test_root_changes_status_to_approved_and_rejected(): void
    {
        $q = Quote::create(['client_name' => 'Spacedrive', 'description' => 'd', 'amount' => 100, 'status' => 'pending']);

        $this->actingAs($this->root, 'sanctum')->patchJson("/api/v1/admin/quotes/{$q->id}/status", ['status' => 'approved'])
            ->assertStatus(200)->assertJsonPath('data.status', 'approved');
        $this->assertSame('approved', $q->fresh()->status);

        $this->actingAs($this->root, 'sanctum')->patchJson("/api/v1/admin/quotes/{$q->id}/status", ['status' => 'rejected'])
            ->assertStatus(200)->assertJsonPath('data.status', 'rejected');
    }

    public function test_status_validation_rejects_bad_value(): void
    {
        $q = Quote::create(['client_name' => 'A', 'description' => 'd', 'amount' => 100, 'status' => 'pending']);
        $this->actingAs($this->root, 'sanctum')->patchJson("/api/v1/admin/quotes/{$q->id}/status", ['status' => 'maybe'])
            ->assertStatus(422);
    }

    public function test_root_deletes_quote(): void
    {
        $q = Quote::create(['client_name' => 'A', 'description' => 'd', 'amount' => 100, 'status' => 'pending']);
        $this->actingAs($this->root, 'sanctum')->deleteJson("/api/v1/admin/quotes/{$q->id}")->assertStatus(200);
        $this->assertDatabaseMissing('quotes', ['id' => $q->id]);
    }

    public function test_non_root_forbidden_everywhere(): void
    {
        $q = Quote::create(['client_name' => 'A', 'description' => 'd', 'amount' => 100, 'status' => 'pending']);

        $this->actingAs($this->standAdmin, 'sanctum')->getJson('/api/v1/admin/quotes')->assertStatus(403);
        $this->actingAs($this->standAdmin, 'sanctum')->postJson('/api/v1/admin/quotes', [
            'client_name' => 'X', 'description' => 'd', 'amount' => 1,
        ])->assertStatus(403);
        $this->actingAs($this->standAdmin, 'sanctum')->getJson("/api/v1/admin/quotes/{$q->id}")->assertStatus(403);
        $this->actingAs($this->standAdmin, 'sanctum')->patchJson("/api/v1/admin/quotes/{$q->id}", ['amount' => 2])->assertStatus(403);
        $this->actingAs($this->standAdmin, 'sanctum')->patchJson("/api/v1/admin/quotes/{$q->id}/status", ['status' => 'approved'])->assertStatus(403);
        $this->actingAs($this->standAdmin, 'sanctum')->deleteJson("/api/v1/admin/quotes/{$q->id}")->assertStatus(403);
    }

    public function test_guest_unauthenticated_blocked(): void
    {
        $this->getJson('/api/v1/admin/quotes')->assertStatus(401);
    }
}
