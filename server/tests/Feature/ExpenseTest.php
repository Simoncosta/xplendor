<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DMS sub-fase 1c.2b — Despesas: CRUD, FKs opcionais, pago+data, regra de
 * eliminação da despesa, e ATIVAÇÃO das regras de arquivo de fornecedor/categoria.
 */
class ExpenseTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Company $otherCompany;
    private User $user;
    private ExpenseCategory $category;
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'Test Plan', 'price' => 0, 'car_limit' => 99,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->company = Company::create([
            'nipc' => '500000050', 'fiscal_name' => 'Test Despesas Lda',
            'plan_id' => $planId, 'subscription_status' => 'active',
        ]);
        $this->otherCompany = Company::create([
            'nipc' => '500000051', 'fiscal_name' => 'Outra Lda',
            'plan_id' => $planId, 'subscription_status' => 'active',
        ]);
        $this->user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);

        $this->category = ExpenseCategory::create(['company_id' => $this->company->id, 'name' => 'Mecânico']);
        $this->supplier = Supplier::create(['company_id' => $this->company->id, 'name' => 'Oficina X']);
    }

    private function url(?string $suffix = null): string
    {
        $base = "/api/v1/companies/{$this->company->id}/expenses";
        return $suffix ? "{$base}{$suffix}" : $base;
    }

    public function test_creates_full_expense(): void
    {
        $payload = [
            'description' => 'Revisão',
            'amount' => 250.50,
            'date' => '2026-09-01',
            'expense_category_id' => $this->category->id,
            'supplier_id' => $this->supplier->id,
            'is_paid' => true,
        ];

        $response = $this->actingAs($this->user, 'sanctum')->postJson($this->url(), $payload);

        $response->assertStatus(200);
        $this->assertDatabaseHas('expenses', [
            'company_id' => $this->company->id,
            'description' => 'Revisão',
            'amount' => 250.50,
            'expense_category_id' => $this->category->id,
            'supplier_id' => $this->supplier->id,
            'is_paid' => true,
        ]);
        // is_paid=true sem paid_at → assume hoje.
        $this->assertSame(now()->toDateString(), Expense::first()->paid_at->toDateString());
    }

    public function test_creates_minimal_expense_without_fks(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->postJson($this->url(), [
            'description' => 'Despesa solta',
            'amount' => 40,
            'date' => '2026-09-02',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('expenses', [
            'description' => 'Despesa solta',
            'expense_category_id' => null,
            'supplier_id' => null,
            'car_id' => null,
            'is_paid' => false,
        ]);
        $this->assertTrue($response->json('data.can_delete')); // sem vínculo → eliminável
    }

    public function test_required_fields(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->postJson($this->url(), []);
        $response->assertStatus(422)->assertJsonValidationErrors(['description', 'amount', 'date']);
    }

    public function test_marking_unpaid_clears_paid_at(): void
    {
        $expense = Expense::create([
            'company_id' => $this->company->id, 'description' => 'X', 'amount' => 10,
            'date' => '2026-09-01', 'is_paid' => true, 'paid_at' => '2026-09-01',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson($this->url("/{$expense->id}"), ['is_paid' => false]);

        $response->assertStatus(200);
        $this->assertNull($expense->fresh()->paid_at);
        $this->assertFalse($expense->fresh()->is_paid);
    }

    public function test_deletes_expense_without_links(): void
    {
        $expense = Expense::create([
            'company_id' => $this->company->id, 'description' => 'Solta', 'amount' => 10, 'date' => '2026-09-01',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->deleteJson($this->url("/{$expense->id}"));
        $response->assertStatus(200);
        $this->assertDatabaseMissing('expenses', ['id' => $expense->id]);
    }

    public function test_linked_expense_cannot_be_deleted_but_can_be_archived(): void
    {
        $expense = Expense::create([
            'company_id' => $this->company->id, 'description' => 'Com categoria', 'amount' => 10,
            'date' => '2026-09-01', 'expense_category_id' => $this->category->id,
        ]);

        // Delete bloqueado (tem vínculo).
        $this->actingAs($this->user, 'sanctum')
            ->deleteJson($this->url("/{$expense->id}"))
            ->assertStatus(422);
        $this->assertDatabaseHas('expenses', ['id' => $expense->id]);

        // Arquivar funciona (partial update).
        $this->actingAs($this->user, 'sanctum')
            ->patchJson($this->url("/{$expense->id}"), ['archived' => true])
            ->assertStatus(200);
        $this->assertTrue($expense->fresh()->archived);
    }

    public function test_list_and_filters(): void
    {
        Expense::create(['company_id' => $this->company->id, 'description' => 'Paga', 'amount' => 100, 'date' => '2026-09-01', 'is_paid' => true, 'supplier_id' => $this->supplier->id]);
        Expense::create(['company_id' => $this->company->id, 'description' => 'Aberta', 'amount' => 50, 'date' => '2026-09-02', 'is_paid' => false]);
        Expense::create(['company_id' => $this->otherCompany->id, 'description' => 'Alheia', 'amount' => 999, 'date' => '2026-09-01']);

        // Lista scoped.
        $all = $this->actingAs($this->user, 'sanctum')->getJson($this->url());
        $all->assertStatus(200);
        $names = collect($all->json('data.data'))->pluck('description');
        $this->assertTrue($names->contains('Paga'));
        $this->assertFalse($names->contains('Alheia'));

        // Filtro por não-pagas.
        $open = $this->actingAs($this->user, 'sanctum')->getJson($this->url('?is_paid=0'));
        $this->assertEquals(['Aberta'], collect($open->json('data.data'))->pluck('description')->all());

        // Filtro por fornecedor.
        $bySupplier = $this->actingAs($this->user, 'sanctum')->getJson($this->url("?supplier_id={$this->supplier->id}"));
        $this->assertEquals(['Paga'], collect($bySupplier->json('data.data'))->pluck('description')->all());
    }

    public function test_summary_totals(): void
    {
        Expense::create(['company_id' => $this->company->id, 'description' => 'A', 'amount' => 100, 'date' => '2026-09-01', 'is_paid' => true]);
        Expense::create(['company_id' => $this->company->id, 'description' => 'B', 'amount' => 40, 'date' => '2026-09-02', 'is_paid' => false]);

        $res = $this->actingAs($this->user, 'sanctum')->getJson($this->url('/summary'));
        $res->assertStatus(200);
        $this->assertSame(140.0, (float) $res->json('data.total_amount'));
        $this->assertSame(100.0, (float) $res->json('data.paid_amount'));
        $this->assertSame(40.0, (float) $res->json('data.open_amount'));
        $this->assertSame(2, $res->json('data.count'));
    }

    // ── Ativação das regras de arquivo (o ponto central da 1c.2b) ──────────

    public function test_supplier_with_expenses_cannot_be_deleted(): void
    {
        Expense::create([
            'company_id' => $this->company->id, 'description' => 'Ligada ao fornecedor',
            'amount' => 10, 'date' => '2026-09-01', 'supplier_id' => $this->supplier->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/companies/{$this->company->id}/suppliers/{$this->supplier->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('suppliers', ['id' => $this->supplier->id]);
    }

    public function test_supplier_can_be_archived(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/companies/{$this->company->id}/suppliers/{$this->supplier->id}", ['archived' => true]);

        $response->assertStatus(200);
        $this->assertTrue($this->supplier->fresh()->archived);
    }

    public function test_tenant_isolation(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/companies/{$this->otherCompany->id}/expenses")
            ->assertStatus(403);
    }
}
