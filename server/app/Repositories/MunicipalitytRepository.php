<?php

namespace App\Repositories;

use App\Models\Municipality;
use App\Repositories\Contracts\MunicipalityRepositoryInterface;

class MunicipalitytRepository extends BaseRepository implements MunicipalityRepositoryInterface
{
    public function __construct(
        Municipality $model,
    ) {
        parent::__construct($model);
    }

    public function getMunicipalities(int $districtId, ?int $perPage = null): mixed
    {
        $query = $this->model->whereDistrictId($districtId)->select(['id', 'name', 'district_id']);

        return $perPage ? $query->paginate($perPage) : $query->get();
    }
}
