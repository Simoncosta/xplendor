<?php

namespace App\Repositories;

use App\Models\Car;
use App\Models\CarMarketSnapshot;
use App\Repositories\Contracts\CarMarketSnapshotRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CarMarketSnapshotRepository extends BaseRepository implements CarMarketSnapshotRepositoryInterface
{
    public function __construct(CarMarketSnapshot $model)
    {
        parent::__construct($model);
    }

    public function upsertSnapshots(array $snapshots): void
    {
        if (empty($snapshots)) {
            return;
        }

        $this->model->newQuery()->upsert(
            $snapshots,
            ['source', 'external_id'],
            [
                'brand',
                'model',
                'year',
                'title',
                'url',
                'category',
                'region',
                'price',
                'price_currency',
                'price_evaluation',
                'km',
                'fuel',
                'gearbox',
                'power_hp',
                'color',
                'doors',
                'scraped_at',
                'updated_at',
            ]
        );
    }

    public function getComparableSnapshots(Car $car): Collection
    {
        $car->loadMissing(['brand:id,name', 'model:id,name']);

        $brand = $this->normalizeComparableValue($car->brand?->name);
        $model = $this->normalizeComparableValue($car->model?->name);
        $year = $car->registration_year ? (int) $car->registration_year : null;
        $fuel = $this->normalizeFuel($car->fuel_type);
        $gearbox = $this->normalizeGearbox($car->transmission);
        $powerHp = $car->power_hp ? (int) $car->power_hp : null;

        if (!$brand || !$model || !$year) {
            return collect();
        }

        return $this->model->newQuery()
            ->select([
                'id',
                'external_id',
                'source',
                'brand',
                'model',
                'year',
                'price',
                'fuel',
                'gearbox',
                'power_hp',
                'region',
                'price_evaluation',
                'scraped_at',
            ])
            ->whereNotNull('price')
            ->where('price', '>', 0)
            ->whereBetween('year', [$year - 1, $year + 1])
            ->whereRaw(
                "LOWER(REPLACE(REPLACE(brand, '-', ''), ' ', '')) = ?",
                [$brand]
            )
            ->whereRaw(
                "LOWER(REPLACE(REPLACE(model, '-', ''), ' ', '')) = ?",
                [$model]
            )
            ->when($fuel, fn($query) => $query->where('fuel', $fuel))
            ->when($gearbox, fn($query) => $query->where('gearbox', $gearbox))
            ->when($powerHp, function ($query) use ($powerHp) {
                $query->where(function ($subQuery) use ($powerHp) {
                    $subQuery
                        ->whereNull('power_hp')
                        ->orWhereBetween('power_hp', [$powerHp - 25, $powerHp + 25]);
                });
            })
            ->orderBy('price')
            ->get();
    }

    public function getSegmentSnapshotStats(array $filters): array
    {
        $baseQuery = $this->buildSegmentQuery($filters);

        $totalListings = (clone $baseQuery)->count();
        $avgPrice = (clone $baseQuery)->avg('price');

        $colorDistribution = (clone $baseQuery)
            ->whereNotNull('color')
            ->where('color', '!=', '')
            ->selectRaw('color, COUNT(*) as total')
            ->groupBy('color')
            ->orderByDesc('total')
            ->get()
            ->map(fn($row) => [
                'value' => $row->color,
                'total' => (int) $row->total,
            ])
            ->values()
            ->all();

        $fuelDistribution = (clone $baseQuery)
            ->whereNotNull('fuel')
            ->where('fuel', '!=', '')
            ->selectRaw('fuel, COUNT(*) as total')
            ->groupBy('fuel')
            ->orderByDesc('total')
            ->get()
            ->map(fn($row) => [
                'value' => $row->fuel,
                'total' => (int) $row->total,
            ])
            ->values()
            ->all();

        $gearboxDistribution = (clone $baseQuery)
            ->whereNotNull('gearbox')
            ->where('gearbox', '!=', '')
            ->selectRaw('gearbox, COUNT(*) as total')
            ->groupBy('gearbox')
            ->orderByDesc('total')
            ->get()
            ->map(fn($row) => [
                'value' => $row->gearbox,
                'total' => (int) $row->total,
            ])
            ->values()
            ->all();

        return [
            'total_listings' => $totalListings,
            'avg_price' => $avgPrice !== null ? round((float) $avgPrice, 2) : null,
            'color_distribution' => $colorDistribution,
            'fuel_distribution' => $fuelDistribution,
            'gearbox_distribution' => $gearboxDistribution,
        ];
    }

    private function buildSegmentQuery(array $filters): Builder
    {
        $brand = $this->normalizeComparableValue($filters['brand'] ?? null);
        $model = $this->normalizeComparableValue($filters['model'] ?? null);
        $fuel = $this->normalizeFuel($filters['fuel'] ?? null);
        $gearbox = $this->normalizeGearbox($filters['gearbox'] ?? null);
        $yearFrom = isset($filters['year_range']['from']) ? (int) $filters['year_range']['from'] : ($filters['year_from'] ?? null);
        $yearTo = isset($filters['year_range']['to']) ? (int) $filters['year_range']['to'] : ($filters['year_to'] ?? null);

        return $this->model->newQuery()
            ->whereNotNull('price')
            ->where('price', '>', 0)
            ->when($brand, fn($query) => $query->whereRaw(
                "LOWER(REPLACE(REPLACE(brand, '-', ''), ' ', '')) = ?",
                [$brand]
            ))
            ->when($model, fn($query) => $query->whereRaw(
                "LOWER(REPLACE(REPLACE(model, '-', ''), ' ', '')) = ?",
                [$model]
            ))
            ->when($fuel, fn($query) => $query->where('fuel', $fuel))
            ->when($gearbox, fn($query) => $query->where('gearbox', $gearbox))
            ->when($yearFrom !== null, fn($query) => $query->where('year', '>=', (int) $yearFrom))
            ->when($yearTo !== null, fn($query) => $query->where('year', '<=', (int) $yearTo));
    }

    private function normalizeComparableValue(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $normalized = $normalized !== false ? $normalized : $value;
        $normalized = strtolower(trim($normalized));
        $normalized = str_replace(['-', '_'], ' ', $normalized);
        $normalized = preg_replace('/[^a-z0-9 ]+/', '', $normalized);
        $normalized = preg_replace('/\s+/', '', (string) $normalized);

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeFuel(?string $value): ?string
    {
        $normalized = $this->normalizeComparableValue($value);

        return match ($normalized) {
            'gasolina', 'petrol', 'gasoline' => 'gasoline',
            'diesel', 'gasoleo' => 'diesel',
            'electric', 'eletrico', 'electrico' => 'electric',
            'hybrid', 'hibrido' => 'hybrid',
            'pluginhybrid', 'pluginhybrid', 'hibridoplugin' => 'plugin_hybrid',
            'lpg', 'gpl' => 'lpg',
            'cng', 'gnv' => 'cng',
            'hydrogen', 'hidrogenio' => 'hydrogen',
            default => $normalized,
        };
    }

    private function normalizeGearbox(?string $value): ?string
    {
        $normalized = $this->normalizeComparableValue($value);

        return match ($normalized) {
            'automatic', 'automatica', 'automatico' => 'automatic',
            'manual' => 'manual',
            'semiautomatic', 'semiautomatica', 'sequencial' => 'semi_automatic',
            default => $normalized,
        };
    }
}
