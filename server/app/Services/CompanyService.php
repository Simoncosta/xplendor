<?php

namespace App\Services;

use App\Models\Plan;
use App\Repositories\Contracts\CompanyRepositoryInterface;

class CompanyService extends BaseService
{
    public function __construct(
        protected CompanyRepositoryInterface $companyRepository,
    ) {
        parent::__construct($companyRepository);
    }
}
