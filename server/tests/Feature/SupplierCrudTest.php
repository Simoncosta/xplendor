<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\District;
use App\Models\Municipality;
use App\Models\Parish;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DMS sub-fase 1c.1 — CRUD + validação + multi-tenancy dos Fornecedores.
 */
class SupplierCrudTest extends TestCase
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
            'nipc'                => '500000030',
            'fiscal_name'         => 'Test Suppliers Lda',
            'plan_id'             => $planId,
            'subscription_status' => 'active',
        ]);

        $this->otherCompany = Company::create([
            'nipc'                => '500000031',
            'fiscal_name'         => 'Outra Empresa Lda',
            'plan_id'             => $planId,
            'subscription_status' => 'active',
        ]);

        $this->user = User::factory()->create([
            'company_id' => $this->company->id,
            'role'       => 'admin',
        ]);
    }

    private function url(?int $id = null): string
    {
        $base = "/api/v1/companies/{$this->company->id}/suppliers";
        return $id ? "{$base}/{$id}" : $base;
    }

    public function test_creates_supplier_with_only_name(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson($this->url(), ['name' => 'Fornecedor Mínimo']);

        $response->assertStatus(200);
        $this->assertDatabaseHas('suppliers', [
            'company_id' => $this->company->id,
            'name'       => 'Fornecedor Mínimo',
        ]);
    }

    public function test_creates_supplier_with_full_data_including_address(): void
    {
        $district = District::create(['name' => 'Coimbra']);
        $municipality = Municipality::create(['name' => 'Coimbra', 'district_id' => $district->id]);
        $parish = Parish::create(['name' => 'Santo António', 'municipality_id' => $municipality->id]);

        $payload = [
            'name'            => 'Fornecedor Completo',
            'nif'             => '500999888',
            'phone'           => '239123456',
            'email'           => 'geral@fornecedor.pt',
            'address'         => 'Rua das Peças, 10',
            'postal_code'     => '3000-100',
            'district_id'     => $district->id,
            'municipality_id' => $municipality->id,
            'parish_id'       => $parish->id,
            'iban'            => 'PT50000201231234567890154',
            'notes'           => 'Fornecedor de confiança',
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson($this->url(), $payload);

        $response->assertStatus(200);
        $this->assertDatabaseHas('suppliers', [
            'company_id'  => $this->company->id,
            'name'        => 'Fornecedor Completo',
            'nif'         => '500999888',
            'district_id' => $district->id,
            'email'       => 'geral@fornecedor.pt',
        ]);
    }

    public function test_name_is_required(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson($this->url(), ['nif' => '123']);

        $response->assertStatus(422)->assertJsonValidationErrors(['name']);
    }

    public function test_invalid_email_is_rejected(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson($this->url(), ['name' => 'X', 'email' => 'nao-e-email']);

        $response->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_list_is_scoped_to_company(): void
    {
        Supplier::create(['company_id' => $this->company->id, 'name' => 'Meu Fornecedor']);
        Supplier::create(['company_id' => $this->otherCompany->id, 'name' => 'Alheio']);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson($this->url() . '?perPage=10');

        $response->assertStatus(200);
        $names = collect($response->json('data.data'))->pluck('name');
        $this->assertTrue($names->contains('Meu Fornecedor'));
        $this->assertFalse($names->contains('Alheio'));
    }

    public function test_admin_cannot_access_other_company_route(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/companies/{$this->otherCompany->id}/suppliers");

        $response->assertStatus(403);
    }

    public function test_cannot_touch_supplier_of_another_company_by_id(): void
    {
        // Fornecedor da OUTRA empresa, mas acedido pela rota da MINHA empresa.
        $alien = Supplier::create(['company_id' => $this->otherCompany->id, 'name' => 'Alien']);

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson($this->url($alien->id), ['name' => 'Hijack']);

        $response->assertStatus(404);
        $this->assertDatabaseHas('suppliers', ['id' => $alien->id, 'name' => 'Alien']);
    }

    public function test_updates_supplier(): void
    {
        $supplier = Supplier::create(['company_id' => $this->company->id, 'name' => 'Antes']);

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson($this->url($supplier->id), ['name' => 'Depois', 'phone' => '911222333']);

        $response->assertStatus(200);
        $this->assertDatabaseHas('suppliers', ['id' => $supplier->id, 'name' => 'Depois', 'phone' => '911222333']);
    }

    public function test_deletes_supplier(): void
    {
        $supplier = Supplier::create(['company_id' => $this->company->id, 'name' => 'A eliminar']);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson($this->url($supplier->id));

        $response->assertStatus(200);
        $this->assertDatabaseMissing('suppliers', ['id' => $supplier->id]);
    }

    public function test_requires_authentication(): void
    {
        $response = $this->getJson($this->url());
        $response->assertStatus(401);
    }
}
