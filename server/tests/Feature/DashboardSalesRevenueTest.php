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
 * Visão 3 do Dashboard (2026-06-25) — FATURAÇÃO (NÃO É LUCRO).
 *
 * Cobre:
 *  - shape `{total_revenue, sales_count, sales_without_value_count, buckets: [...], range: {...}}`;
 *  - SUM(sale_price) por bucket (month e year);
 *  - tratamento honesto de `sale_price = NULL` (sales_count conta tudo;
 *    sales_without_value_count reporta; total_revenue só soma os com valor);
 *  - filtro por range `sold_at` — exclui antes/depois;
 *  - isolamento por company;
 *  - tenant guard (admin outra company 403; root acesso total);
 *  - validação Form Request (range inválido → 422; granularity errado → 422);
 *  - auth obrigatória;
 *  - portabilidade SQLite (precedente scoreModelHistory).
 */
class DashboardSalesRevenueTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User    $admin;
    private int     $planId;
    private int     $brandId;
    private int     $modelId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->planId = DB::table('plans')->insertGetId([
            'name'       => 'Test Plan',
            'price'      => 0,
            'car_limit'  => 999,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->company = Company::create([
            'nipc'                => '500000077',
            'fiscal_name'         => 'Test Sales Revenue Lda',
            'plan_id'             => $this->planId,
            'subscription_status' => 'active',
        ]);

        $this->admin = User::factory()->create([
            'company_id' => $this->company->id,
            'role'       => 'admin',
        ]);

        $brand = CarBrand::firstOrCreate(
            ['slug' => 'renault'],
            ['name' => 'Renault', 'vehicle_type' => 'car'],
        );
        $model = CarModel::firstOrCreate(
            ['name' => 'Clio', 'car_brand_id' => $brand->id],
        );

        $this->brandId = $brand->id;
        $this->modelId = $model->id;
    }

    private function url(int $companyId, array $query = []): string
    {
        $qs = http_build_query($query);
        return "/api/v1/companies/{$companyId}/dashboard/sales-revenue" . ($qs ? "?{$qs}" : '');
    }

    /** Cria um car vendido com sale_price + sold_at na company do test. */
    private function makeSale(string $soldAt, ?float $salePrice, ?int $companyId = null): int
    {
        $companyId = $companyId ?? $this->company->id;

        $car = Car::factory()->create([
            'company_id'    => $companyId,
            'car_brand_id'  => $this->brandId,
            'car_model_id'  => $this->modelId,
            'vehicle_type'  => 'car',
            'status'        => 'sold',
            'sold_at'       => $soldAt,
        ]);

        DB::table('car_sales')->insert([
            'car_id'          => $car->id,
            'company_id'      => $companyId,
            'sale_price'      => $salePrice,
            'buyer_gender'    => 'male',
            'buyer_age_range' => '30-39',
            'sale_channel'    => 'walk_in',
            'contact_consent' => 0,
            'sold_at'         => $soldAt,
            'created_at'      => $soldAt,
            'updated_at'      => $soldAt,
        ]);

        return $car->id;
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_requires_authentication(): void
    {
        $this->getJson($this->url($this->company->id, ['from' => '2026-01-01', 'to' => '2026-12-31']))
            ->assertStatus(401);
    }

    public function test_admin_of_another_company_gets_403(): void
    {
        $otherCompany = Company::create([
            'nipc'                => '500000076',
            'fiscal_name'         => 'Other Lda',
            'plan_id'             => $this->planId,
            'subscription_status' => 'active',
        ]);
        $otherAdmin = User::factory()->create([
            'company_id' => $otherCompany->id,
            'role'       => 'admin',
        ]);

        $this->actingAs($otherAdmin, 'sanctum')
            ->getJson($this->url($this->company->id, ['from' => '2026-01-01', 'to' => '2026-12-31']))
            ->assertStatus(403);
    }

    public function test_root_can_access_any_company(): void
    {
        $root = User::factory()->create([
            'company_id' => $this->company->id,
            'role'       => 'root',
        ]);

        $this->actingAs($root, 'sanctum')
            ->getJson($this->url($this->company->id, ['from' => '2026-01-01', 'to' => '2026-12-31']))
            ->assertStatus(200);
    }

    public function test_missing_required_params_returns_422(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson($this->url($this->company->id))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['from', 'to']);
    }

    public function test_invalid_date_format_returns_422(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson($this->url($this->company->id, ['from' => '01-01-2026', 'to' => '2026-12-31']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['from']);
    }

    public function test_to_before_from_returns_422(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson($this->url($this->company->id, ['from' => '2026-12-31', 'to' => '2026-01-01']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['to']);
    }

    public function test_invalid_granularity_returns_422(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson($this->url($this->company->id, [
                'from' => '2026-01-01',
                'to'   => '2026-12-31',
                'granularity' => 'week',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['granularity']);
    }

    public function test_returns_correct_shape(): void
    {
        $this->makeSale('2026-03-15 10:00:00', 15000);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson($this->url($this->company->id, ['from' => '2026-01-01', 'to' => '2026-12-31']))
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'total_revenue',
                    'sales_count',
                    'sales_without_value_count',
                    'buckets' => [['period', 'revenue', 'sales_count']],
                    'range'   => ['from', 'to', 'granularity'],
                ],
            ]);
    }

    public function test_sums_revenue_correctly_by_month(): void
    {
        // 2 vendas em Janeiro (€10k + €15k), 1 em Março (€8k), 1 em Junho (€20k).
        $this->makeSale('2026-01-10 10:00:00', 10000);
        $this->makeSale('2026-01-25 10:00:00', 15000);
        $this->makeSale('2026-03-15 10:00:00', 8000);
        $this->makeSale('2026-06-05 10:00:00', 20000);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson($this->url($this->company->id, [
                'from' => '2026-01-01',
                'to'   => '2026-12-31',
                'granularity' => 'month',
            ]))
            ->assertStatus(200);

        // JSON strips trailing `.0` em integers exactos, logo cast explícito.
        $this->assertSame(53000.0, (float) $response->json('data.total_revenue'));
        $this->assertSame(4, $response->json('data.sales_count'));
        $this->assertSame(0, $response->json('data.sales_without_value_count'));

        $buckets = $response->json('data.buckets');
        $this->assertCount(3, $buckets);
        $this->assertSame('2026-01', $buckets[0]['period']);
        $this->assertSame(25000.0, (float) $buckets[0]['revenue']);
        $this->assertSame(2, $buckets[0]['sales_count']);
        $this->assertSame('2026-03', $buckets[1]['period']);
        $this->assertSame(8000.0, (float) $buckets[1]['revenue']);
        $this->assertSame(1, $buckets[1]['sales_count']);
        $this->assertSame('2026-06', $buckets[2]['period']);
        $this->assertSame(20000.0, (float) $buckets[2]['revenue']);
        $this->assertSame(1, $buckets[2]['sales_count']);
    }

    public function test_handles_null_sale_price_honestly(): void
    {
        // 2 vendas com valor (€10k + €20k = €30k), 1 sem valor.
        // total_revenue = 30k (NÃO conta a null como 0 que deflacia).
        // sales_count = 3 (TODAS as vendas no período).
        // sales_without_value_count = 1.
        $this->makeSale('2026-03-10 10:00:00', 10000);
        $this->makeSale('2026-03-20 10:00:00', null);     // ⬅ honesto: existe mas sem valor
        $this->makeSale('2026-03-25 10:00:00', 20000);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson($this->url($this->company->id, [
                'from' => '2026-03-01',
                'to'   => '2026-03-31',
            ]))
            ->assertStatus(200);

        $this->assertSame(30000.0, (float) $response->json('data.total_revenue'));
        $this->assertSame(3, $response->json('data.sales_count'));
        $this->assertSame(1, $response->json('data.sales_without_value_count'));

        $buckets = $response->json('data.buckets');
        $this->assertCount(1, $buckets);
        $this->assertSame('2026-03', $buckets[0]['period']);
        $this->assertSame(30000.0, (float) $buckets[0]['revenue']);
        $this->assertSame(3, $buckets[0]['sales_count']);
    }

    public function test_excludes_sales_outside_range(): void
    {
        // Antes do range, dentro, depois.
        $this->makeSale('2025-12-31 23:59:59', 99999); // antes — exclui
        $this->makeSale('2026-03-15 10:00:00', 1000);  // dentro
        $this->makeSale('2027-01-01 00:00:00', 88888); // depois — exclui

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson($this->url($this->company->id, [
                'from' => '2026-01-01',
                'to'   => '2026-12-31',
            ]))
            ->assertStatus(200);

        $this->assertSame(1000.0, (float) $response->json('data.total_revenue'));
        $this->assertSame(1, $response->json('data.sales_count'));
    }

    public function test_includes_full_day_boundaries(): void
    {
        // Vendas exactamente no início (00:00:00) e fim (23:59:59) do range.
        $this->makeSale('2026-03-01 00:00:00', 100);
        $this->makeSale('2026-03-31 23:59:59', 200);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson($this->url($this->company->id, [
                'from' => '2026-03-01',
                'to'   => '2026-03-31',
            ]))
            ->assertStatus(200);

        $this->assertSame(300.0, (float) $response->json('data.total_revenue'));
        $this->assertSame(2, $response->json('data.sales_count'));
    }

    public function test_granularity_year_groups_correctly(): void
    {
        $this->makeSale('2025-06-15 10:00:00', 5000);
        $this->makeSale('2026-03-10 10:00:00', 7000);
        $this->makeSale('2026-09-20 10:00:00', 3000);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson($this->url($this->company->id, [
                'from' => '2025-01-01',
                'to'   => '2026-12-31',
                'granularity' => 'year',
            ]))
            ->assertStatus(200);

        $buckets = $response->json('data.buckets');
        $this->assertCount(2, $buckets);
        $this->assertSame('2025', $buckets[0]['period']);
        $this->assertSame(5000.0, (float) $buckets[0]['revenue']);
        $this->assertSame(1, $buckets[0]['sales_count']);
        $this->assertSame('2026', $buckets[1]['period']);
        $this->assertSame(10000.0, (float) $buckets[1]['revenue']);
        $this->assertSame(2, $buckets[1]['sales_count']);
    }

    public function test_isolates_companies(): void
    {
        $otherCompany = Company::create([
            'nipc'                => '500000075',
            'fiscal_name'         => 'Outra Lda',
            'plan_id'             => $this->planId,
            'subscription_status' => 'active',
        ]);

        // 5 vendas grandes na outra company — NÃO devem aparecer.
        for ($i = 0; $i < 5; $i++) {
            $this->makeSale('2026-03-15 10:00:00', 100000, $otherCompany->id);
        }

        // 1 venda pequena na minha company.
        $this->makeSale('2026-03-15 10:00:00', 500);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson($this->url($this->company->id, [
                'from' => '2026-01-01',
                'to'   => '2026-12-31',
            ]))
            ->assertStatus(200);

        $this->assertSame(500.0, (float) $response->json('data.total_revenue'));
        $this->assertSame(1, $response->json('data.sales_count'));
    }

    public function test_empty_period_returns_zeroes_and_empty_buckets(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson($this->url($this->company->id, [
                'from' => '2026-01-01',
                'to'   => '2026-12-31',
            ]))
            ->assertStatus(200);

        $this->assertSame(0.0, (float) $response->json('data.total_revenue'));
        $this->assertSame(0, $response->json('data.sales_count'));
        $this->assertSame(0, $response->json('data.sales_without_value_count'));
        $this->assertSame([], $response->json('data.buckets'));
    }

    public function test_range_echo_in_response(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson($this->url($this->company->id, [
                'from' => '2026-06-01',
                'to'   => '2026-06-30',
                'granularity' => 'month',
            ]))
            ->assertStatus(200);

        $this->assertSame([
            'from'        => '2026-06-01',
            'to'          => '2026-06-30',
            'granularity' => 'month',
        ], $response->json('data.range'));
    }
}
