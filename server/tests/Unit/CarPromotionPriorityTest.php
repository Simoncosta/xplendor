<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Car;
use App\Models\CarBrand;
use App\Models\CarModel;
use App\Models\CarPromotionPriority;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CarPromotionPriorityTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompany(string $nipc = '500000001'): Company
    {
        // Bypass observer/factory chain (companies tem plan_id obrigatório
        // + slug gerado por observer + outros campos que não nos interessam
        // neste teste). Insert directo, lookup por nipc.
        $planId = DB::table('plans')->insertGetId([
            'name'       => 'Test Plan ' . $nipc,
            'price'      => 0,
            'car_limit'  => 999,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $companyId = DB::table('companies')->insertGetId([
            'fiscal_name' => 'Tester Lda',
            'nipc'        => $nipc,
            'slug'        => 'tester-' . $nipc,
            'plan_id'     => $planId,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
        return Company::findOrFail($companyId);
    }

    private function makeCar(int $companyId): Car
    {
        // Insert directo via DB::table — bypassa observers e factories que
        // não nos interessam neste teste de unit do CarPromotionPriority.
        $brandId = DB::table('car_brands')->insertGetId([
            'name'         => 'TestBrand-' . uniqid(),
            'slug'         => 'testbrand-' . uniqid(),
            'vehicle_type' => 'car',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
        $modelId = DB::table('car_models')->insertGetId([
            'name'         => 'TestModel-' . uniqid(),
            'car_brand_id' => $brandId,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
        $carId = DB::table('cars')->insertGetId([
            'company_id'        => $companyId,
            'car_brand_id'      => $brandId,
            'car_model_id'      => $modelId,
            'version'           => 'X',
            'status'            => 'active',
            'vehicle_type'      => 'car',
            'origin'            => 'national',
            'registration_year' => 2020,
            'doors'             => 4,
            'segment'           => 'sedan',
            'seats'             => 5,
            'exterior_color'    => 'white',
            'condition'         => 'used',
            'has_spare_key'     => false,
            'has_manuals'       => false,
            'is_metallic'       => false,
            'hide_price_online' => false,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
        return Car::findOrFail($carId);
    }

    public function test_table_columns_present(): void
    {
        $cols = Schema::getColumnListing('car_promotion_priorities');
        foreach (['id', 'company_id', 'car_id', 'marked_by_user_id', 'marked_at',
                  'note', 'is_active', 'unmarked_at', 'unmarked_by_user_id',
                  'created_at', 'updated_at'] as $expected) {
            $this->assertContains($expected, $cols, "Coluna em falta: {$expected}");
        }
    }

    public function test_can_create_and_query_active(): void
    {
        $company = $this->makeCompany();
        $user    = User::factory()->create(['company_id' => $company->id]);
        $car     = $this->makeCar($company->id);

        $marked = CarPromotionPriority::create([
            'company_id'        => $company->id,
            'car_id'            => $car->id,
            'marked_by_user_id' => $user->id,
            'marked_at'         => now(),
            'note'              => 'Promoção de Verão',
            'is_active'         => true,
        ]);

        $this->assertTrue($marked->is_active);
        $this->assertSame('Promoção de Verão', $marked->note);
        $this->assertSame(1, CarPromotionPriority::active()->count());
    }

    public function test_scope_active_filters_unmarked(): void
    {
        $company = $this->makeCompany('500000002');
        $car     = $this->makeCar($company->id);

        CarPromotionPriority::create([
            'company_id'  => $company->id,
            'car_id'      => $car->id,
            'marked_at'   => now(),
            'is_active'   => false,
            'unmarked_at' => now(),
        ]);

        $this->assertSame(0, CarPromotionPriority::active()->count());
        $this->assertSame(1, CarPromotionPriority::count());
    }

    public function test_relationships(): void
    {
        $company = $this->makeCompany('500000003');
        $user    = User::factory()->create(['company_id' => $company->id]);
        $car     = $this->makeCar($company->id);

        $p = CarPromotionPriority::create([
            'company_id'        => $company->id,
            'car_id'            => $car->id,
            'marked_by_user_id' => $user->id,
            'marked_at'         => now(),
            'is_active'         => true,
        ]);

        $this->assertSame($car->id,  $p->car->id);
        $this->assertSame($user->id, $p->markedBy->id);
    }
}
