<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Car;
use App\Models\CarBrand;
use App\Models\CarModel;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Visões 1+2 do Dashboard (2026-06-25) — stock visível por marca + tipo.
 *
 * Cobre:
 *  - shape `{by_brand, by_type}` com `name|type` + `count` em cada row;
 *  - filtro "stock visível" (`active|available_soon|reserved`) — exclui
 *    `draft`, `sold`, `inactive`;
 *  - ordenação contagem desc + alfabético desempate;
 *  - tenant guard: admin de outra company → 403;
 *  - root acede a qualquer company;
 *  - auth obrigatória.
 */
class DashboardStockBreakdownTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User    $admin;
    private int     $planId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->planId = DB::table('plans')->insertGetId([
            'name'       => 'Test Plan',
            'price'      => 0,
            'car_limit'  => 99,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->company = Company::create([
            'nipc'                => '500000099',
            'fiscal_name'         => 'Test Stock Breakdown Lda',
            'plan_id'             => $this->planId,
            'subscription_status' => 'active',
        ]);

        $this->admin = User::factory()->create([
            'company_id' => $this->company->id,
            'role'       => 'admin',
        ]);
    }

    private function url(int $companyId): string
    {
        return "/api/v1/companies/{$companyId}/dashboard/stock-breakdown";
    }

    private function makeCar(array $overrides = []): Car
    {
        $brandName = $overrides['brand_name'] ?? 'Renault';
        unset($overrides['brand_name']);

        $brand = CarBrand::firstOrCreate(
            ['slug' => strtolower($brandName)],
            ['name' => $brandName, 'vehicle_type' => 'car'],
        );

        $model = CarModel::firstOrCreate(
            ['name' => 'Modelo Teste', 'car_brand_id' => $brand->id],
        );

        return Car::factory()->create(array_merge([
            'company_id'    => $this->company->id,
            'car_brand_id'  => $brand->id,
            'car_model_id'  => $model->id,
            'vehicle_type'  => 'car',
            'status'        => 'active',
        ], $overrides));
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_requires_authentication(): void
    {
        $this->getJson($this->url($this->company->id))->assertStatus(401);
    }

    public function test_admin_of_another_company_gets_403(): void
    {
        $otherCompany = Company::create([
            'nipc'                => '500000098',
            'fiscal_name'         => 'Other Lda',
            'plan_id'             => $this->planId,
            'subscription_status' => 'active',
        ]);
        $otherAdmin = User::factory()->create([
            'company_id' => $otherCompany->id,
            'role'       => 'admin',
        ]);

        $this->actingAs($otherAdmin, 'sanctum')
            ->getJson($this->url($this->company->id))
            ->assertStatus(403);
    }

    public function test_root_can_access_any_company(): void
    {
        $root = User::factory()->create([
            'company_id' => $this->company->id,
            'role'       => 'root',
        ]);

        $this->actingAs($root, 'sanctum')
            ->getJson($this->url($this->company->id))
            ->assertStatus(200);
    }

    public function test_returns_correct_shape(): void
    {
        $this->makeCar(['brand_name' => 'Renault']);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson($this->url($this->company->id))
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'by_brand' => [['name', 'count']],
                    'by_type'  => [['type', 'count']],
                ],
            ]);
    }

    public function test_counts_brand_correctly_with_descending_order(): void
    {
        // Renault × 3, Hyundai × 2, Peugeot × 2 — ordem esperada:
        // Renault (3), Hyundai (2), Peugeot (2) — desempate alfabético.
        for ($i = 0; $i < 3; $i++) $this->makeCar(['brand_name' => 'Renault']);
        for ($i = 0; $i < 2; $i++) $this->makeCar(['brand_name' => 'Hyundai']);
        for ($i = 0; $i < 2; $i++) $this->makeCar(['brand_name' => 'Peugeot']);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson($this->url($this->company->id))
            ->assertStatus(200);

        $byBrand = $response->json('data.by_brand');

        $this->assertCount(3, $byBrand);
        $this->assertSame(['name' => 'Renault', 'count' => 3], $byBrand[0]);
        // Empate 2-2: ordem alfabética — Hyundai < Peugeot.
        $this->assertSame(['name' => 'Hyundai', 'count' => 2], $byBrand[1]);
        $this->assertSame(['name' => 'Peugeot', 'count' => 2], $byBrand[2]);
    }

    public function test_excludes_invisible_statuses(): void
    {
        // 1 active (conta), 1 draft (NÃO conta), 1 sold (NÃO conta), 1 inactive (NÃO conta).
        $this->makeCar(['brand_name' => 'Renault', 'status' => 'active']);
        $this->makeCar(['brand_name' => 'Renault', 'status' => 'draft']);
        $this->makeCar(['brand_name' => 'Renault', 'status' => 'sold']);
        $this->makeCar(['brand_name' => 'Renault', 'status' => 'inactive']);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson($this->url($this->company->id))
            ->assertStatus(200);

        $this->assertSame([['name' => 'Renault', 'count' => 1]], $response->json('data.by_brand'));
    }

    public function test_includes_available_soon_and_reserved(): void
    {
        $this->makeCar(['brand_name' => 'Renault', 'status' => 'active']);
        $this->makeCar(['brand_name' => 'Renault', 'status' => 'available_soon']);
        $this->makeCar(['brand_name' => 'Renault', 'status' => 'reserved']);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson($this->url($this->company->id))
            ->assertStatus(200);

        $this->assertSame([['name' => 'Renault', 'count' => 3]], $response->json('data.by_brand'));
    }

    public function test_groups_by_vehicle_type(): void
    {
        $this->makeCar(['vehicle_type' => 'car']);
        $this->makeCar(['vehicle_type' => 'car']);
        $this->makeCar(['vehicle_type' => 'motorhome']);
        $this->makeCar(['vehicle_type' => 'caravan']);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson($this->url($this->company->id))
            ->assertStatus(200);

        $byType = $response->json('data.by_type');

        // car (2), caravan (1), motorhome (1) — desc por count, alphabétic em empate.
        $this->assertSame(['type' => 'car', 'count' => 2], $byType[0]);
        $this->assertSame(['type' => 'caravan', 'count' => 1], $byType[1]);
        $this->assertSame(['type' => 'motorhome', 'count' => 1], $byType[2]);
    }

    public function test_isolates_companies(): void
    {
        // Setup outra company com 5 viaturas Hyundai — não devem aparecer.
        $otherCompany = Company::create([
            'nipc'                => '500000097',
            'fiscal_name'         => 'Other Stock Lda',
            'plan_id'             => $this->planId,
            'subscription_status' => 'active',
        ]);

        $brand = CarBrand::firstOrCreate(
            ['slug' => 'hyundai'],
            ['name' => 'Hyundai', 'vehicle_type' => 'car'],
        );
        $model = CarModel::firstOrCreate(
            ['name' => 'Modelo Outro', 'car_brand_id' => $brand->id],
        );

        for ($i = 0; $i < 5; $i++) {
            Car::factory()->create([
                'company_id'    => $otherCompany->id,
                'car_brand_id'  => $brand->id,
                'car_model_id'  => $model->id,
                'vehicle_type'  => 'car',
                'status'        => 'active',
            ]);
        }

        // 1 Renault na MINHA company.
        $this->makeCar(['brand_name' => 'Renault']);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson($this->url($this->company->id))
            ->assertStatus(200);

        // Só vê o seu Renault — não vê os Hyundai da outra company.
        $this->assertSame([['name' => 'Renault', 'count' => 1]], $response->json('data.by_brand'));
    }

    public function test_empty_stock_returns_empty_arrays(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson($this->url($this->company->id))
            ->assertStatus(200);

        $this->assertSame([], $response->json('data.by_brand'));
        $this->assertSame([], $response->json('data.by_type'));
    }
}
