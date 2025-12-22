<?php

namespace App\Services;

use App\Repositories\Contracts\DistrictRepositoryInterface;
use App\Repositories\Contracts\MunicipalityRepositoryInterface;
use App\Repositories\Contracts\ParishRepositoryInterface;

class DistrictService extends BaseService
{
    public function __construct(
        protected DistrictRepositoryInterface $districtRepository,
        protected MunicipalityRepositoryInterface $municipalityRepository,
        protected ParishRepositoryInterface $parishRepository,
    ) {
        parent::__construct($districtRepository);
    }

    public function getMunicipalities(int $districtId, ?int $perPage = null): mixed
    {
        $municipalities = $this->municipalityRepository->getMunicipalities($districtId, $perPage);

        return $municipalities;
    }

    public function getParishes(int $municipalityId, ?int $perPage = null): mixed
    {
        $parishes = $this->parishRepository->getParishes($municipalityId, $perPage);

        return $parishes;
    }
}
