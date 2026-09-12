<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Car;
use App\Models\CarSale;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DMS — Cliente: CRUD, gate de consentimento (RGPD) e regra de arquivo.
 */
class CustomerTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Company $otherCompany;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'Test Plan', 'price' => 0, 'car_limit' => 99,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->company = Company::create(['nipc' => '500000070', 'fiscal_name' => 'Test Clientes Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->otherCompany = Company::create(['nipc' => '500000071', 'fiscal_name' => 'Outra Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
    }

    private function url(?int $id = null): string
    {
        $base = "/api/v1/companies/{$this->company->id}/customers";
        return $id ? "{$base}/{$id}" : $base;
    }

    public function test_creates_customer_with_only_name(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->postJson($this->url(), ['name' => 'João Silva']);
        $response->assertStatus(200);
        $this->assertDatabaseHas('customers', ['company_id' => $this->company->id, 'name' => 'João Silva', 'archived' => false]);
    }

    public function test_name_is_required(): void
    {
        $this->actingAs($this->user, 'sanctum')->postJson($this->url(), ['nif' => '123'])
            ->assertStatus(422)->assertJsonValidationErrors(['name']);
    }

    public function test_creates_full_customer_with_legal_fields(): void
    {
        $payload = [
            'name' => 'Maria Completa', 'nif' => '200000001', 'phone' => '910000000', 'email' => 'maria@x.pt',
            'citizen_card_number' => '12345678', 'citizen_card_validity' => '2030-01-01', 'birth_date' => '1980-05-10',
            'nationality' => 'Portuguesa', 'profession' => 'Advogada', 'marital_status' => 'Casado(a)', 'contact_consent' => true,
        ];
        $response = $this->actingAs($this->user, 'sanctum')->postJson($this->url(), $payload);
        $response->assertStatus(200);
        $this->assertDatabaseHas('customers', [
            'name' => 'Maria Completa', 'nif' => '200000001', 'phone' => '910000000',
            'citizen_card_number' => '12345678', 'nationality' => 'Portuguesa', 'profession' => 'Advogada', 'marital_status' => 'Casado(a)',
        ]);
    }

    public function test_profession_and_nationality_persist_and_are_readable(): void
    {
        // Cria com profissão + nacionalidade → lê de volta (o teste "grava e reabre").
        $create = $this->actingAs($this->user, 'sanctum')->postJson($this->url(), [
            'name' => 'João Motorista', 'profession' => 'Motorista TVDE', 'nationality' => 'Brasileira',
        ]);
        $create->assertStatus(200);
        $id = $create->json('data.id');

        $read = $this->actingAs($this->user, 'sanctum')->getJson($this->url($id));
        $read->assertStatus(200);
        $this->assertSame('Motorista TVDE', $read->json('data.profession'));
        $this->assertSame('Brasileira', $read->json('data.nationality'));

        // Edição parcial só da profissão persiste.
        $this->actingAs($this->user, 'sanctum')->patchJson($this->url($id), ['profession' => 'Empresário'])->assertStatus(200);
        $this->assertSame('Empresário', $this->actingAs($this->user, 'sanctum')->getJson($this->url($id))->json('data.profession'));
    }

    public function test_consent_gate_nulls_contact_pii_on_create(): void
    {
        // Sem consentimento → phone/email NÃO são guardados (name/nif ficam).
        $response = $this->actingAs($this->user, 'sanctum')->postJson($this->url(), [
            'name' => 'Sem Consent', 'nif' => '300000001', 'phone' => '911111111', 'email' => 'no@x.pt', 'contact_consent' => false,
        ]);
        $response->assertStatus(200);
        $this->assertDatabaseHas('customers', ['name' => 'Sem Consent', 'nif' => '300000001', 'phone' => null, 'email' => null]);
    }

    public function test_consent_gate_keeps_contact_pii_when_consented(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->postJson($this->url(), [
            'name' => 'Com Consent', 'phone' => '922222222', 'email' => 'yes@x.pt', 'contact_consent' => true,
        ]);
        $response->assertStatus(200);
        $this->assertDatabaseHas('customers', ['name' => 'Com Consent', 'phone' => '922222222', 'email' => 'yes@x.pt']);
    }

    public function test_consent_gate_applies_on_update_too(): void
    {
        $c = Customer::create(['company_id' => $this->company->id, 'name' => 'X', 'phone' => '933333333', 'email' => 'x@x.pt', 'contact_consent' => true]);

        // Revoga consentimento → phone/email limpos no update.
        $this->actingAs($this->user, 'sanctum')->patchJson($this->url($c->id), ['contact_consent' => false])->assertStatus(200);
        $fresh = $c->fresh();
        $this->assertNull($fresh->phone);
        $this->assertNull($fresh->email);
    }

    public function test_archive_via_update(): void
    {
        $c = Customer::create(['company_id' => $this->company->id, 'name' => 'Arquivável']);
        $this->actingAs($this->user, 'sanctum')->patchJson($this->url($c->id), ['archived' => true])->assertStatus(200);
        $this->assertTrue($c->fresh()->archived);
    }

    public function test_deletes_customer_without_sales(): void
    {
        $c = Customer::create(['company_id' => $this->company->id, 'name' => 'A eliminar']);
        $this->actingAs($this->user, 'sanctum')->deleteJson($this->url($c->id))->assertStatus(200);
        $this->assertDatabaseMissing('customers', ['id' => $c->id]);
    }

    public function test_customer_with_sales_cannot_be_deleted(): void
    {
        $c = Customer::create(['company_id' => $this->company->id, 'name' => 'Com venda']);
        $car = Car::create(['company_id' => $this->company->id, 'vehicle_type' => 'car', 'status' => 'sold']);
        CarSale::create([
            'car_id' => $car->id, 'company_id' => $this->company->id, 'customer_id' => $c->id,
            'sale_price' => 10000, 'buyer_gender' => 'male', 'buyer_age_range' => '31-45', 'sale_channel' => 'in_person', 'sold_at' => now(),
        ]);

        $this->actingAs($this->user, 'sanctum')->deleteJson($this->url($c->id))->assertStatus(422);
        $this->assertDatabaseHas('customers', ['id' => $c->id]);
    }

    public function test_list_scoped_and_tenant_isolation(): void
    {
        Customer::create(['company_id' => $this->company->id, 'name' => 'Meu']);
        Customer::create(['company_id' => $this->otherCompany->id, 'name' => 'Alheio']);

        $res = $this->actingAs($this->user, 'sanctum')->getJson($this->url());
        $names = collect($res->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Meu'));
        $this->assertFalse($names->contains('Alheio'));

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/companies/{$this->otherCompany->id}/customers")->assertStatus(403);
    }

    public function test_cannot_update_customer_of_another_company_by_id(): void
    {
        $alien = Customer::create(['company_id' => $this->otherCompany->id, 'name' => 'Alien']);
        $this->actingAs($this->user, 'sanctum')->putJson($this->url($alien->id), ['name' => 'Hijack'])->assertStatus(404);
        $this->assertDatabaseHas('customers', ['id' => $alien->id, 'name' => 'Alien']);
    }
}
