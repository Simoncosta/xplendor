<?php

namespace App\Repositories\Contracts;

use App\Models\Company;
use App\Models\CompanyOperation;

interface CompanyOperationRepositoryInterface extends BaseRepositoryInterface {
    public function storeCompanyOperation(Company $company, array $data): CompanyOperation;
    public function updateCompanyOperation(CompanyOperation $companyOperation, array $data): CompanyOperation;
    public function deleteCompanyOperation(CompanyOperation $companyOperation): void;
}
