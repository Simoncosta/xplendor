<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Car;
use App\Models\CarBrand;
use App\Models\CarModel;
use App\Models\Company;
use App\Services\CarMarketIntelligenceService;
use App\Services\CarSalePotentialScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IpsPendingStateTest extends TestCase
{
    use RefreshDatabase;

    private function makeCar(string $vehicleType = 'car'): Car
    {
        $planId = DB::table('plans')->insertGetId([
            'name'       => 'IPS Plan',
            'price'      => 0,
            'car_limit'  => 99,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $company = Company::create([
            'nipc'                => '500000020',
            'fiscal_name'         => 'IPS Test Lda',
            'plan_id'             => $planId,
            'subscription_status' => 'active',
        ]);

        $brand = CarBrand::create([
            'name'         => 'Fiat',
            'slug'         => 'fiat',
            'vehicle_type' => $vehicleType,
        ]);

        $model = CarModel::create([
            'name'         => 'Ducato',
            'car_brand_id' => $brand->id,
        ]);

        return Car::factory()->create([
            'company_id'   => $company->id,
            'car_brand_id' => $brand->id,
            'car_model_id' => $model->id,
            'vehicle_type' => $vehicleType,
            'status'       => 'active',
            'price_gross'  => 55000,
        ]);
    }

    private function mockMarket(array $payload): void
    {
        $mock = \Mockery::mock(CarMarketIntelligenceService::class);
        $mock->shouldReceive('analyze')->andReturn($payload);
        $this->app->instance(CarMarketIntelligenceService::class, $mock);
    }

    public function test_no_signals_yields_pending(): void
    {
        $car = $this->makeCar();
        // Sem views (nenhuma seeded) e sem dados de mercado.
        $this->mockMarket(['market_position' => 'insufficient_data']);

        $result = $this->app->make(CarSalePotentialScoreService::class)
            ->calculate($car->id, $car->company_id, 'manual');

        $this->assertNull($result->score);
        $this->assertSame('pending', $result->classification);
    }

    public function test_market_signal_without_views_yields_numeric_score(): void
    {
        $car = $this->makeCar();
        // views=0 mas COM dados de mercado → pontua pelos fatores não-tráfego.
        $this->mockMarket([
            'market_position'         => 'below_market',
            'car_price_vs_median_pct' => -8.0,
        ]);

        $result = $this->app->make(CarSalePotentialScoreService::class)
            ->calculate($car->id, $car->company_id, 'manual');

        $this->assertNotNull($result->score);
        $this->assertIsInt($result->score);
        $this->assertGreaterThan(0, $result->score);
        $this->assertContains($result->classification, ['hot', 'warm', 'cold']);
    }
}
