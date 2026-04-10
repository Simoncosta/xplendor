<?php

namespace App\Repositories\Contracts;

use Illuminate\Support\Collection;

interface CarCategoryRepositoryInterface extends BaseRepositoryInterface
{
    public function getCategoriesByType(string $vehicleType): Collection;
}
