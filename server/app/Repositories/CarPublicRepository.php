<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Car;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class CarPublicRepository
{
    private const ACTIVE_STATUSES = ['active', 'available_soon'];

    private const ALLOWED_ORDER_FIELDS = ['created_at', 'price_gross', 'registration_year', 'mileage_km'];

    public function getPublicCars(
        int $companyId,
        array $filters = [],
        ?int $perPage = null,
        array $orderBy = []
    ): mixed {
        $query = Car::query()
            ->where('company_id', $companyId)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->with(['images', 'externalImages', 'brand', 'model', 'category', 'vehicleAttribute']);

        $this->applyScalarFilters($query, $filters);
        $this->applyJsonFilters($query, $filters);
        $this->applyOrder($query, $orderBy);

        return $perPage ? $query->paginate($perPage) : $query->get();
    }

    public function getPublicFiltersData(int $companyId): EloquentCollection
    {
        return Car::query()
            ->where('company_id', $companyId)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->with(['brand', 'model', 'category', 'vehicleAttribute'])
            ->get();
    }

    private function applyScalarFilters(Builder $query, array $filters): void
    {
        if (isset($filters['doors']) && $filters['doors'] !== null && $filters['doors'] !== '') {
            $query->where('doors', $filters['doors']);
        }

        if (!empty($filters['condition'])) {
            match ($filters['condition']) {
                'new'   => $query->where('condition', 'new'),
                'used'  => $query->whereIn('condition', ['used', 'like_new']),
                default => null,
            };
        }

        if (!empty($filters['price_gross']['between'])) {
            $query->whereBetween('price_gross', $filters['price_gross']['between']);
        }

        $inFilters = [
            'exterior_colors'    => 'exterior_color',
            'interior_colors'    => 'interior_color',
            'registration_years' => 'registration_year',
            'fuel_types'         => 'fuel_type',
            'transmissions'      => 'transmission',
        ];

        foreach ($inFilters as $param => $column) {
            if (!empty($filters[$param])) {
                $query->whereIn($column, (array) $filters[$param]);
            }
        }

        if (!empty($filters['vehicle_type'])) {
            $query->where('vehicle_type', $filters['vehicle_type']);
        }

        if (!empty($filters['segment'])) {
            $query->where('segment', $filters['segment']);
        }

        if (!empty($filters['car_brand_id'])) {
            $query->where('car_brand_id', (int) $filters['car_brand_id']);
        }

        if (!empty($filters['car_model_id'])) {
            $query->where('car_model_id', (int) $filters['car_model_id']);
        }

        if (!empty($filters['category'])) {
            $val = $filters['category'];
            $query->whereHas('category', static function (Builder $q) use ($val) {
                is_numeric($val)
                    ? $q->where('id', (int) $val)
                    : $q->where('slug', $val);
            });
        }

        if (isset($filters['min_seats']) && $filters['min_seats'] !== null) {
            $query->where('seats', '>=', (int) $filters['min_seats']);
        }

        if (isset($filters['max_seats']) && $filters['max_seats'] !== null) {
            $query->where('seats', '<=', (int) $filters['max_seats']);
        }
    }

    private function applyJsonFilters(Builder $query, array $filters): void
    {
        if (!empty($filters['bed_types'])) {
            $bedTypes = (array) $filters['bed_types'];
            $query->whereHas('vehicleAttribute', static function (Builder $q) use ($bedTypes) {
                $q->where(static function (Builder $inner) use ($bedTypes) {
                    foreach ($bedTypes as $bedType) {
                        $inner->orWhereRaw(
                            "JSON_SEARCH(attributes, 'one', ?, NULL, '$.beds[*].type') IS NOT NULL",
                            [$bedType]
                        );
                    }
                });
            });
        }

        if (isset($filters['min_length_m']) && $filters['min_length_m'] !== null) {
            $min = (float) $filters['min_length_m'];
            $query->whereHas('vehicleAttribute', static function (Builder $q) use ($min) {
                $q->whereRaw("JSON_EXTRACT(attributes, '$.dimensions.length_m') >= ?", [$min]);
            });
        }

        if (isset($filters['max_length_m']) && $filters['max_length_m'] !== null) {
            $max = (float) $filters['max_length_m'];
            $query->whereHas('vehicleAttribute', static function (Builder $q) use ($max) {
                $q->whereRaw("JSON_EXTRACT(attributes, '$.dimensions.length_m') <= ?", [$max]);
            });
        }

        foreach (['has_bathroom', 'has_kitchen'] as $key) {
            if (!empty($filters[$key])) {
                $path = "$.habitation_basics.{$key}";
                $query->whereHas('vehicleAttribute', static function (Builder $q) use ($path) {
                    $q->whereRaw("JSON_EXTRACT(attributes, '{$path}') = true");
                });
            }
        }

        if (!empty($filters['has_solar_panel'])) {
            $query->whereHas('vehicleAttribute', static function (Builder $q) {
                $q->whereRaw("JSON_EXTRACT(attributes, '$.energy_climate.has_solar_panel') = true");
            });
        }
    }

    private function applyOrder(Builder $query, array $orderBy): void
    {
        $orderBy = $orderBy ?: ['created_at' => 'asc'];
        foreach ($orderBy as $field => $direction) {
            if (!in_array($field, self::ALLOWED_ORDER_FIELDS, true)) {
                continue;
            }
            $query->orderBy($field, strtolower($direction) === 'desc' ? 'desc' : 'asc');
        }
    }
}
