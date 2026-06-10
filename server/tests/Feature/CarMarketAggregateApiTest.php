<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ScrapeMarketSnapshotJob;
use App\Models\Car;
use App\Models\CarBrand;
use App\Models\CarMarketAggregate;
use App\Models\CarModel;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CarMarketAggregateApiTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User    $user;
    private Car     $car;

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
            'nipc'                => '500000001',
            'fiscal_name'         => 'Test Company Lda',
            'plan_id'             => $planId,
            'subscription_status' => 'active',
        ]);

        $this->user = User::factory()->create([
            'company_id' => $this->company->id,
            'role'       => 'admin',
        ]);

        $brand = CarBrand::create([
            'name'         => 'BMW',
            'slug'         => 'bmw',
            'vehicle_type' => 'car',
        ]);

        $model = CarModel::create([
            'name'         => '320d',
            'car_brand_id' => $brand->id,
        ]);

        $this->car = Car::factory()->create([
            'company_id'        => $this->company->id,
            'car_brand_id'      => $brand->id,
            'car_model_id'      => $model->id,
            'vehicle_type'      => 'car',
            'registration_year' => 2020,
            'price_gross'       => 20000,
        ]);
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/companies/{$this->company->id}/cars/{$this->car->id}/market-aggregate{$suffix}";
    }

    private function makeAggregate(array $overrides = []): CarMarketAggregate
    {
        return CarMarketAggregate::create(array_merge([
            'car_id'            => $this->car->id,
            'vehicle_type'      => 'car',
            'status'            => 'success',
            'confidence'        => 'high',
            'comparables_count' => 5,
            'median_price'      => 19000.00,
            'min_price'         => 16000.00,
            'max_price'         => 22000.00,
            'avg_price'         => 18800.00,
            'car_price_gross'   => 20000.00,
            'top_comparables'   => json_encode([]),
            'fallback_used'     => false,
        ], $overrides));
    }

    // -------------------------------------------------------------------------

    public function test_shapes_response_correctly(): void
    {
        $this->makeAggregate();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson($this->url())
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'status',
                    'confidence',
                    'comparables_count',
                    'prices' => ['median', 'min', 'max', 'avg'],
                    'comparison' => ['car_price', 'difference_percent', 'signal'],
                    'top_comparables',
                    'fallback_used',
                    'hide_price_online',
                    'created_at',
                    'updated_at',
                ],
            ]);

        // MS1.c — hide_price_online emitido como boolean (deriva do car).
        $data = $response->json('data');
        $this->assertIsBool($data['hide_price_online']);
    }

    public function test_market_aggregate_returns_404_for_unknown_car(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/companies/{$this->company->id}/cars/99999/market-aggregate")
            ->assertStatus(404);
    }

    public function test_market_aggregate_returns_403_for_other_company_car(): void
    {
        $planId = DB::table('plans')->insertGetId([
            'name'       => 'Other Plan',
            'price'      => 0,
            'car_limit'  => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $otherCompany = Company::create([
            'nipc'                => '500000002',
            'fiscal_name'         => 'Other Company Lda',
            'plan_id'             => $planId,
            'subscription_status' => 'active',
        ]);

        $otherCar = Car::factory()->create([
            'company_id'        => $otherCompany->id,
            'vehicle_type'      => 'car',
            'registration_year' => 2020,
            'price_gross'       => 15000,
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/companies/{$this->company->id}/cars/{$otherCar->id}/market-aggregate")
            ->assertStatus(404);
    }

    public function test_market_aggregate_returns_null_when_no_data(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson($this->url())
            ->assertStatus(200);

        $this->assertNull($response->json('data'));
    }

    public function test_refresh_market_aggregate_is_rate_limited(): void
    {
        Queue::fake();

        Cache::put("market_refresh:car:{$this->car->id}", true, 300);

        $this->actingAs($this->user, 'sanctum')
            ->postJson($this->url('/refresh'))
            ->assertStatus(429);
    }

    public function test_refresh_market_aggregate_dispatches_job(): void
    {
        Queue::fake();
        Cache::flush();

        $this->actingAs($this->user, 'sanctum')
            ->postJson($this->url('/refresh'))
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonStructure(['data' => ['aggregate_id', 'status']]);

        Queue::assertPushed(ScrapeMarketSnapshotJob::class);
    }

    public function test_market_aggregate_by_specific_id_returns_correct_aggregate(): void
    {
        $first  = $this->makeAggregate(['status' => 'error']);
        $second = $this->makeAggregate(['status' => 'success', 'median_price' => 18000.00]);

        $this->actingAs($this->user, 'sanctum')
            ->getJson($this->url("?aggregate_id={$first->id}"))
            ->assertStatus(200)
            ->assertJsonPath('data.id', $first->id)
            ->assertJsonPath('data.status', 'error');

        $this->actingAs($this->user, 'sanctum')
            ->getJson($this->url("?aggregate_id={$second->id}"))
            ->assertStatus(200)
            ->assertJsonPath('data.id', $second->id)
            ->assertJsonPath('data.status', 'success');
    }

    public function test_market_aggregate_by_id_of_other_car_returns_null(): void
    {
        $otherCar = Car::factory()->create([
            'company_id'        => $this->company->id,
            'vehicle_type'      => 'car',
            'registration_year' => 2021,
            'price_gross'       => 15000,
        ]);

        $otherAggregate = CarMarketAggregate::create([
            'car_id'            => $otherCar->id,
            'vehicle_type'      => 'car',
            'status'            => 'success',
            'confidence'        => 'high',
            'comparables_count' => 3,
            'median_price'      => 14000.00,
            'top_comparables'   => json_encode([]),
            'fallback_used'     => false,
        ]);

        // Request aggregate_id of otherCar's aggregate through this->car's URL
        $this->actingAs($this->user, 'sanctum')
            ->getJson($this->url("?aggregate_id={$otherAggregate->id}"))
            ->assertStatus(200)
            ->assertJsonPath('data', null);
    }
}
