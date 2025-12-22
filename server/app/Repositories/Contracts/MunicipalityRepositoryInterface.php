<?php

namespace App\Repositories\Contracts;

interface MunicipalityRepositoryInterface extends BaseRepositoryInterface
{
    public function getMunicipalities(int $districtId, ?int $perPage = null): mixed;
}
