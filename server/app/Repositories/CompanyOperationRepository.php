<?php

namespace App\Repositories;

use App\Models\Company;
use App\Models\CompanyOperation;
use App\Repositories\Contracts\CompanyOperationRepositoryInterface;

class CompanyOperationRepository extends BaseRepository implements CompanyOperationRepositoryInterface
{
    public function __construct(CompanyOperation $model)
    {
        parent::__construct($model);
    }

    public function storeCompanyOperation(Company $company, array $data): CompanyOperation
    {
        return $company->operations()->create($data);
    }

    public function updateCompanyOperation(CompanyOperation $operation, array $data): CompanyOperation
    {
        $operation->update($data);
        return $operation;
    }

    public function deleteCompanyOperation(CompanyOperation $operation): void
    {
        $operation->delete();
    }
}
