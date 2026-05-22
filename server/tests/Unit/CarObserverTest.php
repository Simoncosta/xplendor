<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Jobs\ScrapeMarketSnapshotJob;
use App\Models\Car;
use App\Models\CarBrand;
use App\Models\CarMarketAggregate;
use App\Models\CarModel;
use App\Models\Company;
use App\Repositories\Contracts\CarMarketSnapshotRepositoryInterface;
use App\Services\MarketSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * These tests exercise MarketSnapshotService::snapshotForCar() directly —
 * the observer guard skips in testing, so we bypass the observer and call
 * the service's dispatch logic, which is what the observer delegates to.
 */
class CarObserverTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
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
            'name'         => 'Fiat',
            'slug'         => 'fiat',
            'vehicle_type' => 'car',
        ]);

        $this->carModel = CarModel::create([
            'name'         => 'Ducato',
            'car_brand_id' => $this->brand->id,
        ]);
    }

    private function makeService(): MarketSnapshotService
    {
        return new MarketSnapshotService(
            $this->app->make(CarMarketSnapshotRepositoryInterface::class)
        );
    }

    private function createCar(array $overrides = []): Car
    {
        $car = Car::factory()->create(array_merge([
            'company_id'        => $this->company->id,
            'car_brand_id'      => $this->brand->id,
            'car_model_id'      => $this->carModel->id,
            'vehicle_type'      => 'car',
            'registration_year' => 2021,
            'price_gross'       => 25000,
        ], $overrides));

        $car->setRelation('brand', $this->brand);
        $car->setRelation('model', $this->carModel);

        return $car;
    }

    // -------------------------------------------------------------------------

    public function test_created_dispatches_job_for_motorhome(): void
    {
        Queue::fake();

        $car = $this->createCar(['vehicle_type' => 'motorhome']);

        $this->makeService()->snapshotForCar($car);

        Queue::assertPushed(ScrapeMarketSnapshotJob::class);

        $aggregate = CarMarketAggregate::where('car_id', $car->id)->first();
        $this->assertNotNull($aggregate);
        $this->assertSame('pending', $aggregate->status);
        $this->assertSame('motorhome', $aggregate->vehicle_type);
    }

    public function test_created_dispatches_job_for_car(): void
    {
        Queue::fake();

        $car = $this->createCar(['vehicle_type' => 'car']);

        $this->makeService()->snapshotForCar($car);

        Queue::assertPushed(ScrapeMarketSnapshotJob::class);
    }

    public function test_created_skips_for_caravan(): void
    {
        Queue::fake();

        // Caravan: no DB needed — service returns null before any write
        $car = new Car(['vehicle_type' => 'caravan', 'registration_year' => 2021]);
        $car->forceFill(['id' => 9999]);
        $car->setRelation('brand', $this->brand);
        $car->setRelation('model', $this->carModel);

        $result = $this->makeService()->snapshotForCar($car);

        $this->assertNull($result);
        Queue::assertNotPushed(ScrapeMarketSnapshotJob::class);
    }

    public function test_created_skips_when_data_incomplete(): void
    {
        Queue::fake();

        // Create a valid car, then force brand relation to null so the service
        // sees it as incomplete. loadMissing() skips relations already loaded.
        $car = $this->createCar();
        $car->setRelation('brand', null);
        $car->setRelation('model', null);

        $result = $this->makeService()->snapshotForCar($car);

        Queue::assertNotPushed(ScrapeMarketSnapshotJob::class);
        $this->assertNotNull($result);
        $this->assertSame('failed', $result->status);
    }
}
