<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * XPLENDOR — Orçamentos de serviços (/admin, só root): validação, listagem com
 * filtros, alteração de rascunho, apagar rascunho e o bloqueio de não-root e de
 * visitantes. O fluxo completo está em QuoteModuleTest.
 */
class AdminQuoteTest extends TestCase
{
    use RefreshDatabase;

    private User $root;
    private User $standAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $planId = DB::table('plans')->insertGetId([
            'name' => 'Test Plan', 'price' => 0, 'car_limit' => 99,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $company = Company::create(['nipc' => '500000700', 'fiscal_name' => 'Empresa A', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->root = User::factory()->create(['company_id' => $company->id, 'role' => 'root']);
        $this->standAdmin = User::factory()->create(['company_id' => $company->id, 'role' => 'admin']);
    }

    private function draft(string $client = 'Spacedrive', string $title = 'Website'): array
    {
        return $this->actingAs($this->root, 'sanctum')->postJson('/api/v1/admin/quotes', [
            'new_customer' => ['name' => $client],
            'title' => $title,
            'lines' => [['name' => 'Website', 'unit' => 'hour', 'billing_type' => 'one_off', 'quantity' => 4, 'unit_price' => 25]],
        ])->assertStatus(201)->json('data');
    }

    public function test_root_creates_draft(): void
    {
        $q = $this->draft();

        $this->assertSame(['draft', 100.0, 'Spacedrive'], [$q['status'], (float) $q['total_one_off'], $q['client_name']]);
        $this->assertDatabaseHas('quotes', ['client_name' => 'Spacedrive', 'status' => 'draft', 'amount' => 100]);
    }

    public function test_validation_requires_customer_and_valid_lines(): void
    {
        $this->actingAs($this->root, 'sanctum')->postJson('/api/v1/admin/quotes', ['title' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors('customer_id');
        $this->actingAs($this->root, 'sanctum')->postJson('/api/v1/admin/quotes', [
            'new_customer' => ['name' => 'A'],
            'lines' => [['name' => '', 'unit' => 'week', 'billing_type' => 'yearly', 'quantity' => 0, 'unit_price' => -1]],
        ])->assertStatus(422)->assertJsonValidationErrors(['lines.0.name', 'lines.0.unit', 'lines.0.billing_type', 'lines.0.quantity', 'lines.0.unit_price']);
        $this->assertSame(0, Quote::count());
    }

    public function test_root_lists_quotes_and_filters_by_status_and_search(): void
    {
        $a = $this->draft('Alfa', 'Redes sociais');
        $this->draft('Beta', 'Website');
        $this->actingAs($this->root, 'sanctum')->postJson("/api/v1/admin/quotes/{$a['id']}/send")->assertOk();

        $this->assertCount(2, $this->actingAs($this->root, 'sanctum')->getJson('/api/v1/admin/quotes')->json('data'));
        $sent = $this->actingAs($this->root, 'sanctum')->getJson('/api/v1/admin/quotes?status=sent')->json('data');
        $this->assertSame(['Alfa'], array_column($sent, 'client_name'));
        $search = $this->actingAs($this->root, 'sanctum')->getJson('/api/v1/admin/quotes?search=Website')->json('data');
        $this->assertSame(['Beta'], array_column($search, 'client_name'));
    }

    public function test_root_updates_draft_in_place(): void
    {
        $q = $this->draft();

        $this->actingAs($this->root, 'sanctum')->patchJson("/api/v1/admin/quotes/{$q['id']}", ['title' => 'Website novo', 'notes' => 'Ligar na segunda'])
            ->assertOk()->assertJsonPath('data.title', 'Website novo')->assertJsonPath('data.version', 1)->assertJsonPath('data.notes', 'Ligar na segunda');
        $this->assertCount(1, Quote::find($q['id'])->lines);   // sem "lines" no pedido, as linhas ficam
    }

    public function test_root_deletes_draft(): void
    {
        $q = $this->draft();
        $this->actingAs($this->root, 'sanctum')->deleteJson("/api/v1/admin/quotes/{$q['id']}")->assertStatus(200);
        $this->assertDatabaseMissing('quotes', ['id' => $q['id']]);
        $this->assertSame(0, DB::table('quote_lines')->count());
    }

    public function test_non_root_forbidden_everywhere(): void
    {
        $q = $this->draft();
        $as = $this->actingAs($this->standAdmin, 'sanctum');

        $as->getJson('/api/v1/admin/quotes')->assertStatus(403);
        $as->postJson('/api/v1/admin/quotes', ['new_customer' => ['name' => 'X']])->assertStatus(403);
        $as->getJson("/api/v1/admin/quotes/{$q['id']}")->assertStatus(403);
        $as->patchJson("/api/v1/admin/quotes/{$q['id']}", ['title' => 'x'])->assertStatus(403);
        $as->postJson("/api/v1/admin/quotes/{$q['id']}/send")->assertStatus(403);
        $as->patchJson("/api/v1/admin/quotes/{$q['id']}/decision", ['decision' => 'accept'])->assertStatus(403);
        $as->postJson("/api/v1/admin/quotes/{$q['id']}/duplicate")->assertStatus(403);
        $as->get("/api/v1/admin/quotes/{$q['id']}/pdf")->assertStatus(403);
        $as->getJson('/api/v1/admin/quotes/summary')->assertStatus(403);
        $as->getJson('/api/v1/admin/quotes/customers')->assertStatus(403);
        $as->deleteJson("/api/v1/admin/quotes/{$q['id']}")->assertStatus(403);
        $this->assertSame('draft', Quote::find($q['id'])->status);
    }

    public function test_guest_unauthenticated_blocked(): void
    {
        $this->getJson('/api/v1/admin/quotes')->assertStatus(401);
    }
}
