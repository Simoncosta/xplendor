<?php

namespace App\Repositories;

use App\Models\CarView;
use App\Repositories\Contracts\CarViewRepositoryInterface;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class CarViewRepository extends BaseRepository implements CarViewRepositoryInterface
{
    public function __construct(CarView $model)
    {
        parent::__construct($model);
    }

    public function countByCar(int $carId): int
    {
        return $this->model->where('car_id', $carId)->count();
    }

    public function countByCarSince(int $carId, CarbonInterface $since): int
    {
        return $this->model
            ->where('car_id', $carId)
            ->where('created_at', '>=', $since)
            ->count();
    }

    public function groupByChannel(int $carId)
    {
        return $this->model
            ->select('channel', DB::raw('COUNT(*) as total'))
            ->where('car_id', $carId)
            ->groupBy('channel')
            ->get();
    }

    public function getTimelineByCar(int $carId)
    {
        return $this->model
            ->select('id', 'created_at')
            ->where('car_id', $carId)
            ->latest('created_at')
            ->get();
    }

    public function getGroupedTimelineByCar(int $carId)
    {
        return $this->model
            ->selectRaw("
            DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') as grouped_at,
            COUNT(*) as total,
            COUNT(DISTINCT visitor_id) as unique_visitors
        ")
            ->where('car_id', $carId)
            ->groupBy('grouped_at')
            ->orderByDesc('grouped_at')
            ->get();
    }
}
