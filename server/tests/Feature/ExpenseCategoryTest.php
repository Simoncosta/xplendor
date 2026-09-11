<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Services\ExpenseCategoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DMS sub-fase 1c.2a — Categorias de Despesa: CRUD, import de sugeridas,
 * regra de arquivo/eliminação e multi-tenancy.
 */
class ExpenseCategoryTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Company $otherCompany;
    private User    $user;

    protected function setUp(): void
    {
        parent::setUp();

        $planId = DB::table('plans')->insertGetId([
            'name'       => 'Test Plan',
            'price'      => 0,
            'car_limit'  => 99,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->company = Company::create([
            'nipc'                => '500000040',
            'fiscal_name'         => 'Test Categorias Lda',
            'plan_id'             => $planId,
            'subscription_status' => 'active',
        ]);

        $this->otherCompany = Company::create([
            'nipc'                => '500000041',
            'fiscal_name'         => 'Outra Lda',
            'plan_id'             => $planId,
            'subscription_status' => 'active',
        ]);

        $this->user = User::factory()->create([
            'company_id' => $this->company->id,
            'role'       => 'admin',
        ]);
    }

    private function url(?string $suffix = null): string
    {
        $base = "/api/v1/companies/{$this->company->id}/expense-categories";
        return $suffix ? "{$base}{$suffix}" : $base;
    }

    public function test_company_starts_empty(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->getJson($this->url());
        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));
    }

    public function test_creates_category_with_only_name(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson($this->url(), ['name' => 'Pintura']);

        $response->assertStatus(200);
        $this->assertDatabaseHas('expense_categories', [
            'company_id' => $this->company->id,
            'name'       => 'Pintura',
            'archived'   => false,
        ]);
    }

    public function test_name_is_required(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson($this->url(), ['color' => '#fff']);

        $response->assertStatus(422)->assertJsonValidationErrors(['name']);
    }

    public function test_import_suggested_creates_fourteen(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson($this->url('/import-suggested'));

        $response->assertStatus(200);
        $this->assertSame(14, $response->json('data.created_count'));
        $this->assertSame(14, ExpenseCategory::where('company_id', $this->company->id)->count());
        $this->assertCount(count(ExpenseCategoryService::SUGGESTED), ExpenseCategoryService::SUGGESTED);
    }

    public function test_import_suggested_is_idempotent(): void
    {
        $this->actingAs($this->user, 'sanctum')->postJson($this->url('/import-suggested'));

        // Segunda importação não duplica nada.
        $response = $this->actingAs($this->user, 'sanctum')->postJson($this->url('/import-suggested'));
        $response->assertStatus(200);
        $this->assertSame(0, $response->json('data.created_count'));
        $this->assertSame(14, ExpenseCategory::where('company_id', $this->company->id)->count());
    }

    public function test_import_suggested_only_creates_missing(): void
    {
        ExpenseCategory::create(['company_id' => $this->company->id, 'name' => 'Pintura']);

        $response = $this->actingAs($this->user, 'sanctum')->postJson($this->url('/import-suggested'));
        $response->assertStatus(200);
        // 14 sugeridas menos a "Pintura" já existente = 13 criadas.
        $this->assertSame(13, $response->json('data.created_count'));
        $this->assertSame(14, ExpenseCategory::where('company_id', $this->company->id)->count());
    }

    public function test_deletes_category_without_expenses(): void
    {
        $cat = ExpenseCategory::create(['company_id' => $this->company->id, 'name' => 'Temporária']);

        $response = $this->actingAs($this->user, 'sanctum')->deleteJson($this->url("/{$cat->id}"));

        $response->assertStatus(200);
        $this->assertDatabaseMissing('expense_categories', ['id' => $cat->id]);
    }

    public function test_archive_via_update_persists(): void
    {
        $cat = ExpenseCategory::create(['company_id' => $this->company->id, 'name' => 'Arquivável']);

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson($this->url("/{$cat->id}"), ['name' => 'Arquivável', 'archived' => true]);

        $response->assertStatus(200);
        $this->assertTrue($cat->fresh()->archived === true);
    }

    /**
     * BUG 1c.2a: arquivar enviava SÓ `archived` (sem `name`) e o `name=required`
     * no update rebentava com 422. O update passou a aceitar partial (name sometimes).
     */
    public function test_archive_with_only_archived_field_succeeds(): void
    {
        $cat = ExpenseCategory::create(['company_id' => $this->company->id, 'name' => 'Só arquivar']);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson($this->url("/{$cat->id}"), ['archived' => true]);

        $response->assertStatus(200);
        $fresh = $cat->fresh();
        $this->assertTrue($fresh->archived === true);
        $this->assertSame('Só arquivar', $fresh->name); // nome preservado

        // Restaurar também (partial, só archived=false).
        $this->actingAs($this->user, 'sanctum')
            ->patchJson($this->url("/{$cat->id}"), ['archived' => false])
            ->assertStatus(200);
        $this->assertTrue($cat->fresh()->archived === false);
    }

    public function test_suggested_endpoint_returns_fourteen(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->getJson($this->url('/suggested'));

        $response->assertStatus(200);
        $this->assertCount(14, $response->json('data.suggested'));
        $this->assertContains('Pintura', $response->json('data.suggested'));
    }

    public function test_category_with_expenses_cannot_be_deleted(): void
    {
        // 1c.2b: com a tabela `expenses` real, a regra bloqueia de verdade.
        $cat = ExpenseCategory::create(['company_id' => $this->company->id, 'name' => 'Com histórico']);
        \App\Models\Expense::create([
            'company_id'          => $this->company->id,
            'description'         => 'Serviço',
            'amount'              => 100,
            'date'                => now()->toDateString(),
            'expense_category_id' => $cat->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->deleteJson($this->url("/{$cat->id}"));

        $response->assertStatus(422);
        $this->assertDatabaseHas('expense_categories', ['id' => $cat->id]); // não foi eliminada
    }

    public function test_list_is_scoped_to_company(): void
    {
        ExpenseCategory::create(['company_id' => $this->company->id, 'name' => 'Minha']);
        ExpenseCategory::create(['company_id' => $this->otherCompany->id, 'name' => 'Alheia']);

        $response = $this->actingAs($this->user, 'sanctum')->getJson($this->url());
        $names = collect($response->json('data'))->pluck('name');

        $this->assertTrue($names->contains('Minha'));
        $this->assertFalse($names->contains('Alheia'));
    }

    public function test_admin_cannot_access_other_company_route(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/companies/{$this->otherCompany->id}/expense-categories");

        $response->assertStatus(403);
    }

    public function test_cannot_update_category_of_another_company_by_id(): void
    {
        $alien = ExpenseCategory::create(['company_id' => $this->otherCompany->id, 'name' => 'Alien']);

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson($this->url("/{$alien->id}"), ['name' => 'Hijack']);

        $response->assertStatus(404);
        $this->assertDatabaseHas('expense_categories', ['id' => $alien->id, 'name' => 'Alien']);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson($this->url())->assertStatus(401);
    }
}
