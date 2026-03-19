<?php

namespace App\Repositories\Contracts;

use Illuminate\Support\Collection;

interface CompanyIntegrationRepositoryInterface extends BaseRepositoryInterface
{
    public function getCompanyIntegrations(int $companyId): Collection;
}
