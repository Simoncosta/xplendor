<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Jobs\RefreshStaleMarketAggregatesJob;
use App\Models\Car;
use App\Models\CarBrand;
use App\Models\CarMarketAggregate;
use App\Models\CarModel;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RefreshStaleMarketAggregatesJobTest extends TestCase
{
    use RefreshDatabase;

    private Company  $company;
    private CarBrand $brand;
    private CarModel $carModel;

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
            'nipc'        => '500000001',
            'fiscal_name' => 'Test Company Lda',
            'plan_id'     => $planId,
        ]);

        $this->brand = CarBrand::create([
            'name'         => 'BMW',
            'slug'         => 'bmw',
            'vehicle_type' => 'car',
        ]);

        $this->carModel = CarModel::create([
            'name'         => '320d',
            'car_brand_id' => $this->brand->id,
        ]);
    }

    private function makeCar(array $overrides = []): Car
    {
        return Car::factory()->create(array_merge([
            'company_id'        => $this->company->id,
            'car_brand_id'      => $this->brand->id,
            'car_model_id'      => $this->carModel->id,
            'vehicle_type'      => 'car',
            'status'            => 'active',
            'registration_year' => 2020,
            'price_gross'       => 20000,
        ], $overrides));
    }

    private function makeAggregate(Car $car, array $overrides = []): CarMarketAggregate
    {
        return CarMarketAggregate::create(array_merge([
            'car_id'            => $car->id,
            'vehicle_type'      => $car->vehicle_type,
            'status'            => 'success',
            'confidence'        => 'high',
            'comparables_count' => 5,
            'median_price'      => 18000,
            'car_price_gross'   => 20000,
            'fallback_used'     => false,
        ], $overrides));
    }

    private function resolve(): \Illuminate\Support\Collection
    {
        return (new RefreshStaleMarketAggregatesJob())->resolveStaleCars();
    }

    // -------------------------------------------------------------------------

    public function test_resolves_cars_without_aggregate(): void
    {
        $car = $this->makeCar();

        $result = $this->resolve();

        $this->assertTrue($result->contains('id', $car->id));
    }

    public function test_resolves_cars_with_stale_aggregate(): void
    {
        $car = $this->makeCar();
        $agg = $this->makeAggregate($car);

        // Force updated_at to 8 days ago
        CarMarketAggregate::where('id', $agg->id)
            ->update(['updated_at' => now()->subDays(8)]);

        $result = $this->resolve();

        $this->assertTrue($result->contains('id', $car->id));
    }

    public function test_excludes_cars_with_fresh_aggregate(): void
    {
        $car = $this->makeCar();
        $this->makeAggregate($car); // updated_at = now()

        $result = $this->resolve();

        $this->assertFalse($result->contains('id', $car->id));
    }

    public function test_excludes_inactive_cars(): void
    {
        foreach (['sold', 'draft', 'inactive'] as $status) {
            $car = $this->makeCar(['status' => $status]);
            $this->assertFalse($this->resolve()->contains('id', $car->id), "status={$status} should be excluded");
        }
    }

    public function test_excludes_caravan_and_motorcycle(): void
    {
        foreach (['caravan', 'motorcycle'] as $type) {
            $car = $this->makeCar(['vehicle_type' => $type]);
            $this->assertFalse($this->resolve()->contains('id', $car->id), "vehicle_type={$type} should be excluded");
        }
    }

    public function test_includes_available_soon_cars(): void
    {
        // 'available_soon' is a valid status for refresh alongside 'active'
        $car = $this->makeCar(['status' => 'available_soon']);

        $result = $this->resolve();

        $this->assertTrue($result->contains('id', $car->id));
    }

    public function test_caps_at_20_cars(): void
    {
        // Create 25 eligible cars (no aggregates)
        for ($i = 0; $i < 25; $i++) {
            $this->makeCar();
        }

        $result = $this->resolve();

        $this->assertLessThanOrEqual(20, $result->count());
    }
}
