<?php

namespace App\Repositories;

use App\Models\CarLead;
use App\Repositories\Contracts\CarLeadRepositoryInterface;

class CarLeadRepository extends BaseRepository implements CarLeadRepositoryInterface
{
    public function __construct(CarLead $model)
    {
        parent::__construct($model);
    }

    public function countByCar(int $carId): int
    {
        return $this->model->where('car_id', $carId)->count();
    }

    public function getTimelineByCar(int $carId)
    {
        return $this->model
            ->select('id', 'created_at')
            ->where('car_id', $carId)
            ->latest('created_at')
            ->get();
    }
}
