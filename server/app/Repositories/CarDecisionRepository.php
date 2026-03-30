<?php

namespace App\Repositories;

use App\Models\Car;
use App\Repositories\Contracts\CarDecisionRepositoryInterface;
use Illuminate\Support\Facades\DB;

class CarDecisionRepository implements CarDecisionRepositoryInterface
{
    public function getPerformanceSnapshot(int $carId, int $companyId): array
    {
        $cut7 = now()->subDays(7);
        $cut14 = now()->subDays(14);
        $cut30 = now()->subDays(30);

        $views = DB::table('car_views')
            ->where('company_id', $companyId)
            ->where('car_id', $carId)
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as last_7d,
                SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as last_14d,
                SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as last_30d
            ', [$cut7, $cut14, $cut30])
            ->first();

        $leads = DB::table('car_leads')
            ->where('company_id', $companyId)
            ->where('car_id', $carId)
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as last_7d,
                SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as last_14d,
                SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as last_30d
            ', [$cut7, $cut14, $cut30])
            ->first();

        $interactions = DB::table('car_interactions')
            ->where('company_id', $companyId)
            ->where('car_id', $carId)
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as last_7d,
                SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as last_14d,
                SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as last_30d
            ', [$cut7, $cut14, $cut30])
            ->first();

        return [
            'views_total' => (int) ($views->total ?? 0),
            'views_7d' => (int) ($views->last_7d ?? 0),
            'views_14d' => (int) ($views->last_14d ?? 0),
            'views_30d' => (int) ($views->last_30d ?? 0),
            'leads_total' => (int) ($leads->total ?? 0),
            'leads_7d' => (int) ($leads->last_7d ?? 0),
            'leads_14d' => (int) ($leads->last_14d ?? 0),
            'leads_30d' => (int) ($leads->last_30d ?? 0),
            'interactions_total' => (int) ($interactions->total ?? 0),
            'interactions_7d' => (int) ($interactions->last_7d ?? 0),
            'interactions_14d' => (int) ($interactions->last_14d ?? 0),
            'interactions_30d' => (int) ($interactions->last_30d ?? 0),
        ];
    }

    public function getStockBenchmark(Car $car): array
    {
        $car->loadMissing(['brand:id,name', 'model:id,name']);

        $price = $car->price_gross ? (float) $car->price_gross : null;
        $year = $car->registration_year ? (int) $car->registration_year : null;

        $similarCars = Car::query()
            ->where('company_id', $car->company_id)
            ->where('id', '!=', $car->id)
            ->whereIn('status', ['active', 'sold'])
            ->when($car->segment, fn($query) => $query->where('segment', $car->segment))
            ->when($car->fuel_type, fn($query) => $query->where('fuel_type', $car->fuel_type))
            ->when($car->transmission, fn($query) => $query->where('transmission', $car->transmission))
            ->when($year, fn($query) => $query->whereBetween('registration_year', [$year - 2, $year + 2]))
            ->when($price, fn($query) => $query->whereBetween('price_gross', [$price * 0.8, $price * 1.2]))
            ->withCount(['views', 'leads', 'interactions'])
            ->get(['id', 'price_gross', 'created_at']);

        $count = $similarCars->count();

        if ($count === 0) {
            return [
                'comparables_count' => 0,
                'avg_views' => null,
                'avg_leads' => null,
                'avg_interactions' => null,
                'avg_days_in_stock' => null,
                'avg_interest_rate' => null,
                'avg_conversion_rate' => null,
            ];
        }

        $interestRates = $similarCars->map(function ($item) {
            if ((int) $item->views_count <= 0) {
                return null;
            }

            return (($item->interactions_count + $item->leads_count) / $item->views_count) * 100;
        })->filter();

        $conversionRates = $similarCars->map(function ($item) {
            if ((int) $item->views_count <= 0) {
                return null;
            }

            return ($item->leads_count / $item->views_count) * 100;
        })->filter();

        return [
            'comparables_count' => $count,
            'avg_views' => round((float) $similarCars->avg('views_count'), 2),
            'avg_leads' => round((float) $similarCars->avg('leads_count'), 2),
            'avg_interactions' => round((float) $similarCars->avg('interactions_count'), 2),
            'avg_days_in_stock' => round((float) $similarCars->avg(fn($item) => $item->created_at?->diffInDays(now()) ?? 0), 1),
            'avg_interest_rate' => $interestRates->isNotEmpty() ? round((float) $interestRates->avg(), 2) : null,
            'avg_conversion_rate' => $conversionRates->isNotEmpty() ? round((float) $conversionRates->avg(), 2) : null,
        ];
    }
}
