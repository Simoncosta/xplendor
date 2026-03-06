<?php

namespace App\Repositories;

use App\Models\CarInteraction;
use App\Repositories\Contracts\CarInteractionRepositoryInterface;
use Illuminate\Support\Facades\DB;

class CarInteractionRepository extends BaseRepository implements CarInteractionRepositoryInterface
{
    public function __construct(CarInteraction $model)
    {
        parent::__construct($model);
    }

    public function countByCar(int $carId): int
    {
        return $this->model->where('car_id', $carId)->count();
    }

    public function groupByType(int $carId)
    {
        return $this->model
            ->select('interaction_type', DB::raw('COUNT(*) as total'))
            ->where('car_id', $carId)
            ->groupBy('interaction_type')
            ->get();
    }

    public function getTimelineByCar(int $carId)
    {
        return $this->model
            ->select('id', 'interaction_type', 'created_at')
            ->where('car_id', $carId)
            ->latest('created_at')
            ->get();
    }
}
