<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Car;
use App\Models\CarSale;
use App\Models\Company;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DMS Fase 2A — margem por viatura + margem agregada no sales-revenue.
 * Cobre a fórmula, o tratamento de nulos honesto e o flag uses_vat (rótulo).
 */
class MarginTest extends TestCase
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

        $this->company = Company::create([
            'nipc' => '500000060', 'fiscal_name' => 'Test Margem Lda',
            'plan_id' => $planId, 'subscription_status' => 'active',
            'uses_vat' => false,
        ]);
        $this->user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
    }

    private function makeCar(?float $purchasePrice, string $status = 'sold', ?int $companyId = null): Car
    {
        return Car::create([
            'company_id'     => $companyId ?? $this->company->id,
            'vehicle_type'   => 'car',
            'status'         => $status,
            'purchase_price' => $purchasePrice,
        ]);
    }

    private function makeSale(Car $car, ?float $salePrice, string $soldAt = '2026-09-10 10:00:00'): CarSale
    {
        return CarSale::create([
            'car_id'          => $car->id,
            'company_id'      => $car->company_id,
            'sale_price'      => $salePrice,
            'buyer_gender'    => 'male',
            'buyer_age_range' => '25-34',
            'sale_channel'    => 'direct',
            'sold_at'         => $soldAt,
        ]);
    }

    private function marginUrl(int $carId): string
    {
        return "/api/v1/companies/{$this->company->id}/cars/{$carId}/margin";
    }

    // ── Margem por viatura ────────────────────────────────────────────────

    public function test_margin_with_purchase_and_expenses(): void
    {
        $car = $this->makeCar(10000.0);
        $this->makeSale($car, 15000.0);

        Expense::create(['company_id' => $this->company->id, 'car_id' => $car->id, 'description' => 'Paga', 'amount' => 500, 'date' => '2026-09-01', 'is_paid' => true]);
        Expense::create(['company_id' => $this->company->id, 'car_id' => $car->id, 'description' => 'Aberta', 'amount' => 300, 'date' => '2026-09-02', 'is_paid' => false]);
        // Arquivada NÃO conta.
        Expense::create(['company_id' => $this->company->id, 'car_id' => $car->id, 'description' => 'Arquivada', 'amount' => 999, 'date' => '2026-09-03', 'archived' => true]);
        // Despesa geral (sem car_id) NÃO conta.
        Expense::create(['company_id' => $this->company->id, 'car_id' => null, 'description' => 'Geral', 'amount' => 777, 'date' => '2026-09-04']);

        $res = $this->actingAs($this->user, 'sanctum')->getJson($this->marginUrl($car->id));

        $res->assertStatus(200);
        $this->assertTrue($res->json('data.calculable'));
        $this->assertSame(15000.0, (float) $res->json('data.sale_price'));
        $this->assertSame(10000.0, (float) $res->json('data.purchase_price'));
        $this->assertSame(800.0, (float) $res->json('data.expenses_total')); // 500 + 300 (pagas+abertas), sem arquivada nem geral
        // margem = 15000 - 10000 - 800 = 4200
        $this->assertSame(4200.0, (float) $res->json('data.margin'));
    }

    public function test_margin_without_expenses(): void
    {
        $car = $this->makeCar(8000.0);
        $this->makeSale($car, 10000.0);

        $res = $this->actingAs($this->user, 'sanctum')->getJson($this->marginUrl($car->id));
        $res->assertStatus(200);
        $this->assertSame(2000.0, (float) $res->json('data.margin'));
    }

    public function test_margin_not_calculable_without_purchase_price(): void
    {
        $car = $this->makeCar(null); // sem custo de compra
        $this->makeSale($car, 12000.0);

        $res = $this->actingAs($this->user, 'sanctum')->getJson($this->marginUrl($car->id));
        $res->assertStatus(200);
        $this->assertFalse($res->json('data.calculable'));
        $this->assertSame('no_purchase_price', $res->json('data.reason'));
        $this->assertNull($res->json('data.margin')); // NÃO assume 0
    }

    public function test_margin_not_calculable_when_not_sold(): void
    {
        $car = $this->makeCar(9000.0, 'active');

        $res = $this->actingAs($this->user, 'sanctum')->getJson($this->marginUrl($car->id));
        $res->assertStatus(200);
        $this->assertFalse($res->json('data.calculable'));
        $this->assertSame('not_sold', $res->json('data.reason'));
    }

    // ── Rótulo (uses_vat) ─────────────────────────────────────────────────

    public function test_uses_vat_flag_drives_label(): void
    {
        $car = $this->makeCar(5000.0);
        $this->makeSale($car, 7000.0);

        // uses_vat = false (empresa deste teste).
        $res = $this->actingAs($this->user, 'sanctum')->getJson($this->marginUrl($car->id));
        $this->assertFalse($res->json('data.uses_vat'));

        // uses_vat = true → o FE mostra "margem bruta (sem IVA)".
        $this->company->update(['uses_vat' => true]);
        $res2 = $this->actingAs($this->user, 'sanctum')->getJson($this->marginUrl($car->id));
        $this->assertTrue($res2->json('data.uses_vat'));
    }

    // ── Margem agregada por período (sales-revenue) ───────────────────────

    public function test_sales_revenue_aggregates_margin_with_honest_nulls(): void
    {
        // 2 vendas calculáveis + 1 venda sem custo de compra.
        $c1 = $this->makeCar(10000.0); $this->makeSale($c1, 15000.0, '2026-09-05 10:00:00'); // margem 5000
        Expense::create(['company_id' => $this->company->id, 'car_id' => $c1->id, 'description' => 'X', 'amount' => 1000, 'date' => '2026-09-01']);
        // c1 margem = 15000 - 10000 - 1000 = 4000

        $c2 = $this->makeCar(20000.0); $this->makeSale($c2, 26000.0, '2026-09-08 10:00:00'); // margem 6000

        $c3 = $this->makeCar(null); $this->makeSale($c3, 9000.0, '2026-09-09 10:00:00'); // sem custo → fora da margem

        $url = "/api/v1/companies/{$this->company->id}/dashboard/sales-revenue?from=2026-09-01&to=2026-09-30&granularity=month";
        $res = $this->actingAs($this->user, 'sanctum')->getJson($url);

        $res->assertStatus(200);
        // Receita inclui TODAS as vendas.
        $this->assertSame(50000.0, (float) $res->json('data.total_revenue')); // 15000+26000+9000
        // Margem só das calculáveis: 4000 + 6000 = 10000.
        $this->assertSame(10000.0, (float) $res->json('data.total_margin'));
        $this->assertSame(2, $res->json('data.margin_sales_count'));
        $this->assertSame(1, $res->json('data.margin_without_cost_count')); // c3 sem custo
        $this->assertFalse($res->json('data.uses_vat'));
        // Bucket de Setembro.
        $bucket = collect($res->json('data.buckets'))->firstWhere('period', '2026-09');
        $this->assertNotNull($bucket);
        $this->assertSame(10000.0, (float) $bucket['margin']);
        $this->assertSame(2, $bucket['margin_count']);
    }

    public function test_margin_tenant_isolation(): void
    {
        $other = Company::create(['nipc' => '500000061', 'fiscal_name' => 'Outra', 'plan_id' => $this->company->plan_id, 'subscription_status' => 'active']);
        $alienCar = $this->makeCar(1000.0, 'sold', $other->id);

        $res = $this->actingAs($this->user, 'sanctum')->getJson($this->marginUrl($alienCar->id));
        $res->assertStatus(404); // não encontrada no scope da minha empresa
    }
}
