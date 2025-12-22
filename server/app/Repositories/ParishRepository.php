<?php

namespace App\Repositories;

use App\Models\Parish;
use App\Repositories\Contracts\ParishRepositoryInterface;

class ParishRepository extends BaseRepository implements ParishRepositoryInterface
{
    public function __construct(
        Parish $model,
    ) {
        parent::__construct($model);
    }

    public function getParishes(int $municipalityId, ?int $perPage = null): mixed
    {
        $query = $this->model->whereMunicipalityId($municipalityId)->select(['id', 'name', 'municipality_id']);

        return $perPage ? $query->paginate($perPage) : $query->get();
    }
}
