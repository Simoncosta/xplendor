<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyOperation;
use App\Repositories\Contracts\CompanyOperationRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CompanyOperationService extends BaseService
{
    public function __construct(protected CompanyOperationRepositoryInterface $companyOperationRepository)
    {
        parent::__construct($companyOperationRepository);
    }

    public function storeCompanyOperation(int $companyId, array $data): CompanyOperation
    {
        $company = Company::findOrFail($companyId);
        return $this->companyOperationRepository->storeCompanyOperation($company, $data);
    }

    public function updateCompanyOperation(int $companyId, int $operationId, array $data): CompanyOperation
    {
        $operation = CompanyOperation::where('company_id', $companyId)
            ->findOrFail($operationId);

        return $this->companyOperationRepository->updateCompanyOperation($operation, $data);
    }

    public function deleteCompanyOperation(int $companyId, int $operationId): void
    {
        $operation = CompanyOperation::where('company_id', $companyId)
            ->findOrFail($operationId);

        $this->companyOperationRepository->deleteCompanyOperation($operation);
    }
}
