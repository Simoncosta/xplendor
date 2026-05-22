<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Car;
use App\Models\CarBrand;
use App\Models\CarMarketSnapshot;
use App\Models\CarModel;
use App\Repositories\Contracts\CarMarketSnapshotRepositoryInterface;
use App\Services\MarketSnapshotService;
use Illuminate\Support\Collection;
use Tests\TestCase;

class MarketSnapshotServiceTest extends TestCase
{
    private function makeService(): MarketSnapshotService
    {
        return new MarketSnapshotService(
            $this->app->make(CarMarketSnapshotRepositoryInterface::class)
        );
    }

    private function makeSnapshot(float $price, array $extra = []): CarMarketSnapshot
    {
        $s = new CarMarketSnapshot();
        $s->forceFill(array_merge([
            'external_id'  => 'sv-' . uniqid(),
            'source'       => 'standvirtual',
            'title'        => 'Test Car 2020',
            'url'          => 'https://standvirtual.com/anuncio/' . rand(1, 9999),
            'brand'        => 'BMW',
            'model'        => '320d',
            'year'         => 2020,
            'price'        => $price,
            'fuel'         => 'diesel',
            'gearbox'      => 'automatic',
            'region'       => 'Lisboa',
        ], $extra));

        return $s;
    }

    // -------------------------------------------------------------------------
    // buildFilters
    // -------------------------------------------------------------------------

    public function test_buildFilters_sets_correct_vehicle_type_for_car(): void
    {
        $car = new Car();
        $car->forceFill(['vehicle_type' => 'car', 'registration_year' => 2021, 'fuel_type' => 'Diesel']);

        $brandModel = new CarBrand();
        $brandModel->forceFill(['name' => 'BMW']);
        $car->setRelation('brand', $brandModel);

        $modelModel = new CarModel();
        $modelModel->forceFill(['name' => '3 Series']);
        $car->setRelation('model', $modelModel);

        $filters = $this->makeService()->buildFilters($car);

        $this->assertSame('car', $filters['vehicle_type']);
        $this->assertSame(2020, $filters['year_from']);
        $this->assertSame(2022, $filters['year_to']);
        $this->assertSame(10, $filters['max_results']);
    }

    public function test_buildFilters_sets_motorhome_vehicle_type(): void
    {
        $car = new Car();
        $car->forceFill(['vehicle_type' => 'motorhome', 'registration_year' => 2019]);

        $brandModel = new CarBrand();
        $brandModel->forceFill(['name' => 'Fiat']);
        $car->setRelation('brand', $brandModel);

        $modelModel = new CarModel();
        $modelModel->forceFill(['name' => 'Ducato']);
        $car->setRelation('model', $modelModel);

        $filters = $this->makeService()->buildFilters($car);

        $this->assertSame('motorhome', $filters['vehicle_type']);
        $this->assertSame('Fiat', $filters['brand']);
        $this->assertSame('Ducato', $filters['model']);
    }

    // -------------------------------------------------------------------------
    // computeAggregateData — confidence levels
    // -------------------------------------------------------------------------

    public function test_computeAggregate_high_confidence_with_five_or_more_snapshots(): void
    {
        $snapshots = collect([
            $this->makeSnapshot(20000),
            $this->makeSnapshot(22000),
            $this->makeSnapshot(21000),
            $this->makeSnapshot(19000),
            $this->makeSnapshot(23000),
        ]);

        $data = $this->makeService()->computeAggregateData($snapshots);

        $this->assertSame('success', $data['status']);
        $this->assertSame('high', $data['confidence']);
        $this->assertSame(5, $data['comparables_count']);
        // Sorted: 19000, 20000, 21000, 22000, 23000 → median = 21000
        $this->assertSame(21000.0, (float) $data['median_price']);
    }

    public function test_computeAggregate_medium_confidence_with_three_to_four_snapshots(): void
    {
        $snapshots = collect([
            $this->makeSnapshot(10000),
            $this->makeSnapshot(11000),
            $this->makeSnapshot(12000),
        ]);

        $data = $this->makeService()->computeAggregateData($snapshots);

        $this->assertSame('success', $data['status']);
        $this->assertSame('medium', $data['confidence']);
        $this->assertSame(3, $data['comparables_count']);
    }

    public function test_computeAggregate_low_confidence_with_one_or_two_snapshots(): void
    {
        $snapshots = collect([$this->makeSnapshot(15000)]);

        $data = $this->makeService()->computeAggregateData($snapshots);

        $this->assertSame('success', $data['status']);
        $this->assertSame('low', $data['confidence']);
        $this->assertSame(1, $data['comparables_count']);
    }

    public function test_computeAggregate_none_with_zero_snapshots(): void
    {
        $data = $this->makeService()->computeAggregateData(collect());

        $this->assertSame('none', $data['status']);
        $this->assertSame('none', $data['confidence']);
        $this->assertSame(0, $data['comparables_count']);
        $this->assertNull($data['top_comparables']);
    }

    public function test_computeAggregate_median_correct_for_even_count(): void
    {
        // Sorted: 10000, 20000, 30000, 40000 → median = (20000 + 30000) / 2 = 25000
        $snapshots = collect([
            $this->makeSnapshot(40000),
            $this->makeSnapshot(10000),
            $this->makeSnapshot(30000),
            $this->makeSnapshot(20000),
        ]);

        $data = $this->makeService()->computeAggregateData($snapshots);

        $this->assertSame(25000.0, (float) $data['median_price']);
    }

    // -------------------------------------------------------------------------
    // top_comparables — picks closest to median, not cheapest
    // -------------------------------------------------------------------------

    public function test_topComparables_picks_closest_to_median_not_cheapest(): void
    {
        // Median of [1000, 5000, 10000, 15000, 20000] = 10000
        // Three outliers far from median: 1000 (dist 9000), 20000 (dist 10000)
        // Closest 5: 10000 (0), 5000 (5000), 15000 (5000), 1000 (9000), 20000 (10000)
        $snapshots = collect([
            $this->makeSnapshot(1000,  ['external_id' => 'sv-cheap']),
            $this->makeSnapshot(5000,  ['external_id' => 'sv-mid-low']),
            $this->makeSnapshot(10000, ['external_id' => 'sv-median']),
            $this->makeSnapshot(15000, ['external_id' => 'sv-mid-high']),
            $this->makeSnapshot(20000, ['external_id' => 'sv-expensive']),
        ]);

        $data = $this->makeService()->computeAggregateData($snapshots);

        $this->assertCount(5, $data['top_comparables']);
        // Closest to median (10000) should be first
        $this->assertSame(10000.0, (float) $data['top_comparables'][0]['price']);
    }

    public function test_topComparables_limited_to_five_even_with_more_comparables(): void
    {
        $snapshots = collect(array_map(
            fn ($i) => $this->makeSnapshot(10000 + $i * 1000),
            range(0, 9)
        ));

        $data = $this->makeService()->computeAggregateData($snapshots);

        $this->assertCount(5, $data['top_comparables']);
    }

    public function test_computeAggregate_fallback_used_flag_propagated(): void
    {
        $snapshots = collect([$this->makeSnapshot(20000)]);

        $data = $this->makeService()->computeAggregateData($snapshots, true);

        $this->assertTrue($data['fallback_used']);
    }

    public function test_computeAggregate_min_max_correct(): void
    {
        $snapshots = collect([
            $this->makeSnapshot(5000),
            $this->makeSnapshot(15000),
            $this->makeSnapshot(10000),
        ]);

        $data = $this->makeService()->computeAggregateData($snapshots);

        $this->assertSame(5000.0, (float) $data['min_price']);
        $this->assertSame(15000.0, (float) $data['max_price']);
    }
}
