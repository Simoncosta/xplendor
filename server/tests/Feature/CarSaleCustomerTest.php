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
 * DMS — ligação Cliente ↔ Venda: customer_id nullable, retro-compat da leitura,
 * e consentimento CONSISTENTE (o buraco da edição corrigido).
 */
class CarSaleCustomerTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'Test Plan', 'price' => 0, 'car_limit' => 99,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->company = Company::create(['nipc' => '500000080', 'fiscal_name' => 'Test Venda Cliente Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
    }

    private function soldCar(): Car
    {
        return Car::create(['company_id' => $this->company->id, 'vehicle_type' => 'car', 'status' => 'sold', 'sold_at' => now()]);
    }

    private function saleUrl(int $carId): string
    {
        return "/api/v1/companies/{$this->company->id}/cars/{$carId}/sale";
    }

    private function specsUrl(int $carId): string
    {
        return "/api/v1/companies/{$this->company->id}/cars/{$carId}/specs";
    }

    public function test_update_sale_links_customer(): void
    {
        $car = $this->soldCar();
        $customer = Customer::create(['company_id' => $this->company->id, 'name' => 'Cliente Ligado']);
        // Venda já existe (fluxo real: editar uma venda para lhe ligar o cliente).
        CarSale::create([
            'car_id' => $car->id, 'company_id' => $this->company->id,
            'sale_price' => 12000, 'buyer_gender' => 'male', 'buyer_age_range' => '31-45', 'sale_channel' => 'in_person', 'sold_at' => now(),
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->patchJson($this->saleUrl($car->id), ['customer_id' => $customer->id])
            ->assertStatus(200);

        $this->assertDatabaseHas('car_sales', ['car_id' => $car->id, 'customer_id' => $customer->id]);
    }

    public function test_specs_reads_customer_when_linked(): void
    {
        $car = $this->soldCar();
        $customer = Customer::create(['company_id' => $this->company->id, 'name' => 'Ana Retro', 'nif' => '400000001']);
        CarSale::create([
            'car_id' => $car->id, 'company_id' => $this->company->id, 'customer_id' => $customer->id,
            'sale_price' => 9000, 'buyer_gender' => 'female', 'buyer_age_range' => '31-45', 'sale_channel' => 'online', 'sold_at' => now(),
        ]);

        $res = $this->actingAs($this->user, 'sanctum')->getJson($this->specsUrl($car->id));
        $res->assertStatus(200);
        $this->assertSame($customer->id, $res->json('data.sale.customer_id'));
        $this->assertSame('Ana Retro', $res->json('data.sale.customer.name'));
    }

    public function test_specs_retrocompat_reads_legacy_buyer_without_customer(): void
    {
        // Venda ANTIGA: sem customer_id, com buyer_* legado.
        $car = $this->soldCar();
        CarSale::create([
            'car_id' => $car->id, 'company_id' => $this->company->id, 'customer_id' => null,
            'sale_price' => 8000, 'buyer_gender' => 'male', 'buyer_age_range' => '46-60', 'sale_channel' => 'in_person',
            'buyer_name' => 'Comprador Legado', 'contact_consent' => true, 'sold_at' => now(),
        ]);

        $res = $this->actingAs($this->user, 'sanctum')->getJson($this->specsUrl($car->id));
        $res->assertStatus(200);
        $this->assertNull($res->json('data.sale.customer'));
        $this->assertNull($res->json('data.sale.customer_id'));
        $this->assertSame('Comprador Legado', $res->json('data.sale.buyer_name')); // legado continua a ler-se
    }

    // ── Consentimento consistente (o buraco da EDIÇÃO corrigido) ──────────

    public function test_edit_respects_consent_false_nulls_pii(): void
    {
        $car = $this->soldCar();
        // Cria o sale primeiro.
        CarSale::create([
            'car_id' => $car->id, 'company_id' => $this->company->id,
            'sale_price' => 5000, 'buyer_gender' => 'male', 'buyer_age_range' => '18-30', 'sale_channel' => 'online', 'sold_at' => now(),
        ]);

        // PATCH com PII mas SEM consentimento → PII descartada (antes gravava — buraco).
        $this->actingAs($this->user, 'sanctum')
            ->patchJson($this->saleUrl($car->id), [
                'buyer_name' => 'Secreto', 'buyer_phone' => '966666666', 'buyer_email' => 's@x.pt', 'contact_consent' => false,
            ])->assertStatus(200);

        $this->assertDatabaseHas('car_sales', [
            'car_id' => $car->id, 'buyer_name' => null, 'buyer_phone' => null, 'buyer_email' => null, 'contact_consent' => false,
        ]);
    }

    public function test_edit_keeps_pii_when_consented(): void
    {
        $car = $this->soldCar();
        CarSale::create([
            'car_id' => $car->id, 'company_id' => $this->company->id,
            'sale_price' => 5000, 'buyer_gender' => 'male', 'buyer_age_range' => '18-30', 'sale_channel' => 'online', 'sold_at' => now(),
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->patchJson($this->saleUrl($car->id), [
                'buyer_name' => 'Público', 'buyer_phone' => '977777777', 'contact_consent' => true,
            ])->assertStatus(200);

        $this->assertDatabaseHas('car_sales', ['car_id' => $car->id, 'buyer_name' => 'Público', 'buyer_phone' => '977777777']);
    }
}
