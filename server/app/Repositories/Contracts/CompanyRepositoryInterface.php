<?php

namespace App\Repositories\Contracts;

use App\Models\Company;

interface CompanyRepositoryInterface extends BaseRepositoryInterface {
    public function syncSocialLinks(Company $company, array $socialLinks): void;
}
