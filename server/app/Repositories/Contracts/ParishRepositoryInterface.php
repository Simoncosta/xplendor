<?php

namespace App\Repositories\Contracts;

interface ParishRepositoryInterface extends BaseRepositoryInterface
{
    public function getParishes(int $municipalityId, ?int $perPage = null): mixed;
}
