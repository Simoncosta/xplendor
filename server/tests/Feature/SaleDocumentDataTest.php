<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Car;
use App\Models\CarBrand;
use App\Models\CarModel;
use App\Models\CarSale;
use App\Models\Company;
use App\Models\Customer;
use App\Models\District;
use App\Models\Municipality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DMS Fase 3 — dados dos documentos de venda: preenchimento a partir das
 * entidades (empresa + viatura + cliente) e caso sem cliente.
 */
class SaleDocumentDataTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;
    private Municipality $municipality;

    protected function setUp(): void
    {
        parent::setUp();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'Test Plan', 'price' => 0, 'car_limit' => 99,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $district = District::create(['name' => 'Porto']);
        $this->municipality = Municipality::create(['name' => 'Rio Tinto', 'district_id' => $district->id]);

        $this->company = Company::create([
            'nipc' => '517047241', 'fiscal_name' => 'Arnaldo Sousa Unipessoal Lda',
            'address' => 'Rua X', 'postal_code' => '4435-450', 'phone' => '937051554',
            'municipality_id' => $this->municipality->id,
            'plan_id' => $planId, 'subscription_status' => 'active',
        ]);
        $this->user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
    }

    private function soldCarWithSale(?Customer $customer): Car
    {
        $brand = CarBrand::create(['name' => 'Opel', 'slug' => 'opel', 'vehicle_type' => 'car']);
        $model = CarModel::create(['name' => 'Corsa', 'car_brand_id' => $brand->id]);
        $car = Car::create([
            'company_id' => $this->company->id, 'vehicle_type' => 'car', 'status' => 'sold',
            'car_brand_id' => $brand->id, 'car_model_id' => $model->id,
            'license_plate' => '63-RB-10', 'registration_month' => 1, 'registration_year' => 2013, 'sold_at' => now(),
        ]);
        CarSale::create([
            'car_id' => $car->id, 'company_id' => $this->company->id,
            'customer_id' => $customer?->id,
            'sale_price' => 9000, 'buyer_gender' => 'male', 'buyer_age_range' => '31-45', 'sale_channel' => 'in_person', 'sold_at' => '2026-08-01',
        ]);
        return $car;
    }

    private function url(int $carId): string
    {
        return "/api/v1/companies/{$this->company->id}/cars/{$carId}/sale-document-data";
    }

    public function test_fills_from_entities(): void
    {
        $customer = Customer::create([
            'company_id' => $this->company->id, 'name' => 'Carlos Quintão', 'nif' => '304879681',
            'email' => 'carlos@x.pt', 'address' => 'Rua Alberto Sampaio', 'postal_code' => '4760-292',
            'municipality_id' => $this->municipality->id,
        ]);
        $car = $this->soldCarWithSale($customer);

        $res = $this->actingAs($this->user, 'sanctum')->getJson($this->url($car->id));
        $res->assertStatus(200);

        // Empresa.
        $this->assertSame('Arnaldo Sousa Unipessoal Lda', $res->json('data.company.fiscal_name'));
        $this->assertSame('517047241', $res->json('data.company.nipc'));
        $this->assertSame('Rio Tinto', $res->json('data.company.locality'));

        // Viatura.
        $this->assertSame('Opel', $res->json('data.car.brand'));
        $this->assertSame('63-RB-10', $res->json('data.car.license_plate'));
        $this->assertSame(1, $res->json('data.car.registration_month'));
        $this->assertSame(2013, $res->json('data.car.registration_year'));

        // Cliente.
        $this->assertSame('Carlos Quintão', $res->json('data.customer.name'));
        $this->assertSame('304879681', $res->json('data.customer.nif'));
        $this->assertSame('Rua Alberto Sampaio', $res->json('data.customer.address'));
        $this->assertSame('Rio Tinto', $res->json('data.customer.locality'));
        $this->assertSame('carlos@x.pt', $res->json('data.customer.email'));

        // Venda (data por defeito).
        $this->assertSame('2026-08-01', $res->json('data.sale.sold_at'));
    }

    public function test_customer_null_when_sale_has_no_customer(): void
    {
        $car = $this->soldCarWithSale(null);
        $res = $this->actingAs($this->user, 'sanctum')->getJson($this->url($car->id));
        $res->assertStatus(200);
        $this->assertNull($res->json('data.customer'));
        // Empresa e viatura continuam preenchidas.
        $this->assertSame('Opel', $res->json('data.car.brand'));
    }

    public function test_tenant_isolation(): void
    {
        $other = Company::create(['nipc' => '500999999', 'fiscal_name' => 'Outra', 'plan_id' => $this->company->plan_id, 'subscription_status' => 'active']);
        $alienCar = Car::create(['company_id' => $other->id, 'vehicle_type' => 'car', 'status' => 'sold']);

        $this->actingAs($this->user, 'sanctum')->getJson($this->url($alienCar->id))->assertStatus(404);
        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/companies/{$other->id}/cars/{$alienCar->id}/sale-document-data")->assertStatus(403);
    }
}
