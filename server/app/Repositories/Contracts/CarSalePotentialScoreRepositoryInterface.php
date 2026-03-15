<?php

namespace App\Repositories\Contracts;

use App\Models\CarSalePotentialScore;
use Illuminate\Database\Eloquent\Collection;

interface CarSalePotentialScoreRepositoryInterface
{
    public function getLatest(int $carId, int $companyId): ?CarSalePotentialScore;

    public function getHistory(int $carId, int $companyId, int $days = 90): Collection;

    public function create(array $data): CarSalePotentialScore;

    public function getTopScores(int $companyId, int $limit = 10): Collection;
}
