<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ScrapeMarketSnapshotJob;
use App\Models\Car;
use App\Models\CarMarketAggregate;
use App\Repositories\Contracts\CarMarketSnapshotRepositoryInterface;
use Illuminate\Support\Collection;

class MarketSnapshotService
{
    private const SCRAPER_SUPPORTED_TYPES = ['car', 'motorhome'];

    public function __construct(
        private readonly CarMarketSnapshotRepositoryInterface $snapshotRepo,
    ) {}

    /**
     * Creates an aggregate record and dispatches a scrape job.
     * Called by CarObserver::created().
     * Returns null for unsupported vehicle types (e.g. caravan).
     */
    public function snapshotForCar(Car $car): ?CarMarketAggregate
    {
        $car->loadMissing(['brand:id,name', 'model:id,name']);

        if (!\in_array($car->vehicle_type, self::SCRAPER_SUPPORTED_TYPES, true)) {
            return null;
        }

        // Insufficient data — record failure without invoking the scraper
        if (!$car->brand?->name || !$car->model?->name || !$car->registration_year) {
            return CarMarketAggregate::create([
                'car_id'            => $car->id,
                'vehicle_type'      => $car->vehicle_type ?? 'car',
                'car_price_gross'   => $car->price_gross,
                'status'            => 'failed',
                'confidence'        => 'none',
                'comparables_count' => 0,
                'fallback_used'     => false,
            ]);
        }

        $aggregate = CarMarketAggregate::create([
            'car_id'            => $car->id,
            'vehicle_type'      => $car->vehicle_type ?? 'car',
            'car_price_gross'   => $car->price_gross,
            'status'            => 'pending',
            'confidence'        => 'none',
            'comparables_count' => 0,
            'fallback_used'     => false,
        ]);

        ScrapeMarketSnapshotJob::dispatch($car->id, $aggregate->id);

        return $aggregate;
    }

    /**
     * Builds the scraper filter array for a car (testable without docker exec).
     */
    public function buildFilters(Car $car): array
    {
        $filters = [
            'vehicle_type' => $car->vehicle_type ?? 'car',
            'brand'        => $car->brand?->name,
            'model'        => $car->model?->name,
            'max_results'  => 10,
        ];

        if ($car->registration_year) {
            $filters['year_from'] = $car->registration_year - 1;
            $filters['year_to']   = $car->registration_year + 1;
        }

        if ($car->fuel_type) {
            $filters['fuel'] = $car->fuel_type;
        }

        return $filters;
    }

    /**
     * Implements the three-attempt fallback chain. Called by the job after scraping.
     *
     * @return array{snapshots: Collection, fallback_used: bool}
     */
    public function getComparables(Car $car): array
    {
        // Attempt 1: strict — year ±1, fuel, gearbox, power ±25
        $snapshots = $this->snapshotRepo->getComparableSnapshots($car);
        if ($snapshots->count() >= 3) {
            return ['snapshots' => $snapshots, 'fallback_used' => false];
        }

        // Attempt 2: widen year window (car ±3, motorhome ±5)
        $yearWindow = ($car->vehicle_type === 'motorhome') ? 5 : 3;
        $snapshots  = $this->snapshotRepo->getComparableSnapshotsWide($car, $yearWindow);
        if ($snapshots->isNotEmpty()) {
            return ['snapshots' => $snapshots, 'fallback_used' => true];
        }

        // Attempt 3 (cars only): drop fuel/gearbox/power — brand + model + year only
        if (($car->vehicle_type ?? 'car') === 'car') {
            $snapshots = $this->snapshotRepo->getComparableSnapshotsLoose($car, $yearWindow);
            if ($snapshots->isNotEmpty()) {
                return ['snapshots' => $snapshots, 'fallback_used' => true];
            }
        }

        return ['snapshots' => collect(), 'fallback_used' => false];
    }

    /**
     * Pure computation — no DB writes. Returns the data array ready for persistence.
     */
    public function computeAggregateData(Collection $comparables, bool $fallbackUsed = false): array
    {
        $prices = $comparables
            ->pluck('price')
            ->map(fn ($p) => (float) $p)
            ->filter(fn ($p) => $p > 0.0)
            ->sort()
            ->values();

        if ($prices->isEmpty()) {
            return [
                'status'            => 'none',
                'confidence'        => 'none',
                'comparables_count' => 0,
                'fallback_used'     => $fallbackUsed,
                'top_comparables'   => null,
            ];
        }

        $count  = $prices->count();
        $median = $this->computeMedian($prices);
        $avg    = round($prices->avg(), 2);
        $stdDev = $this->computeStdDev($prices, $avg);

        return [
            'status'            => 'success',
            'confidence'        => $this->deriveConfidence($count),
            'comparables_count' => $count,
            'median_price'      => $median,
            'min_price'         => round((float) $prices->min(), 2),
            'max_price'         => round((float) $prices->max(), 2),
            'avg_price'         => $avg,
            'std_dev'           => $stdDev,
            'fallback_used'     => $fallbackUsed,
            'top_comparables'   => $this->selectTop5($comparables, $median),
        ];
    }

    /**
     * Computes the aggregate and persists it to the given aggregate record.
     * Called by ScrapeMarketSnapshotJob after a successful scrape.
     */
    public function computeAndPersistAggregate(
        Car $car,
        int $aggregateId,
        Collection $comparables,
        bool $fallbackUsed = false,
    ): void {
        $data = $this->computeAggregateData($comparables, $fallbackUsed);

        if (isset($data['top_comparables']) && \is_array($data['top_comparables'])) {
            $data['top_comparables'] = json_encode($data['top_comparables']);
        }

        $data['updated_at'] = now();

        CarMarketAggregate::where('id', $aggregateId)->update($data);
    }

    /** Updates only the status column (used by job on error/block). */
    public function persistAggregateStatus(int $aggregateId, string $status): void
    {
        CarMarketAggregate::where('id', $aggregateId)->update([
            'status'     => $status,
            'updated_at' => now(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function computeMedian(Collection $sortedPrices): float
    {
        $count = $sortedPrices->count();

        if ($count % 2 === 0) {
            return round(((float) $sortedPrices[$count / 2 - 1] + (float) $sortedPrices[$count / 2]) / 2.0, 2);
        }

        return round((float) $sortedPrices[(int) ($count / 2)], 2);
    }

    private function computeStdDev(Collection $prices, float $avg): float
    {
        $variance = $prices->map(fn ($p) => ($p - $avg) ** 2)->avg();

        return round(sqrt((float) $variance), 2);
    }

    private function deriveConfidence(int $count): string
    {
        return match (true) {
            $count >= 5 => 'high',
            $count >= 3 => 'medium',
            $count >= 1 => 'low',
            default     => 'none',
        };
    }

    private function selectTop5(Collection $comparables, float $median): array
    {
        return $comparables
            ->sortBy(fn ($s) => abs((float) $s->price - $median))
            ->take(5)
            ->map(fn ($s) => [
                'external_id' => $s->external_id,
                'source'      => $s->source,
                'title'       => $s->title ?? "{$s->brand} {$s->model} {$s->year}",
                'url'         => $s->url,
                'price'       => (float) $s->price,
                'year'        => $s->year,
                'fuel'        => $s->fuel,
                'gearbox'     => $s->gearbox,
                'region'      => $s->region,
            ])
            ->values()
            ->toArray();
    }
}
