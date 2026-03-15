<?php

namespace App\Repositories;

use App\Models\CarSalePotentialScore;
use App\Repositories\Contracts\CarSalePotentialScoreRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class CarSalePotentialScoreRepository implements CarSalePotentialScoreRepositoryInterface
{
    public function getLatest(int $carId, int $companyId): ?CarSalePotentialScore
    {
        return CarSalePotentialScore::forCompany($companyId)
            ->forCar($carId)
            ->latest('calculated_at')
            ->first();
    }

    public function getHistory(int $carId, int $companyId, int $days = 90): Collection
    {
        return CarSalePotentialScore::forCompany($companyId)
            ->forCar($carId)
            ->where('calculated_at', '>=', now()->subDays($days))
            ->orderBy('calculated_at')
            ->get(['score', 'classification', 'calculated_at', 'triggered_by']);
    }

    public function create(array $data): CarSalePotentialScore
    {
        return CarSalePotentialScore::create($data);
    }

    public function getTopScores(int $companyId, int $limit = 10): Collection
    {
        return CarSalePotentialScore::forCompany($companyId)
            ->whereIn('id', function ($sub) use ($companyId) {
                $sub->selectRaw('MAX(id)')
                    ->from('car_sale_potential_scores')
                    ->where('company_id', $companyId)
                    ->groupBy('car_id');
            })
            ->orderByDesc('score')
            ->limit($limit)
            ->with('car:id,car_brand_id,car_model_id,price_gross,mileage_km')
            ->get();
    }
}
