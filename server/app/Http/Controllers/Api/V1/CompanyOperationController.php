<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCompanyOperationRequest;
use App\Http\Requests\UpdateCompanyOperationRequest;
use App\Http\Resources\CompanyOperationResource;
use App\Services\CompanyOperationService;
use Illuminate\Http\Request;

class CompanyOperationController extends Controller
{
    public function __construct(protected CompanyOperationService $companyOperationService) {}

    public function store(StoreCompanyOperationRequest $request, $companyId)
    {
        $companyOperations = $this->companyOperationService->storeCompanyOperation($companyId, $request->validated());
        return ApiResponse::success(new CompanyOperationResource($companyOperations), 'Company operations fetched successfully.');
    }

    public function update(UpdateCompanyOperationRequest $request, $companyId, $operationId)
    {
        $operation = $this->companyOperationService->updateCompanyOperation(
            $companyId,
            $operationId,
            $request->validated()
        );

        return ApiResponse::success(new CompanyOperationResource($operation), 'Updated successfully.');
    }

    public function destroy($companyId, $operationId)
    {
        $this->companyOperationService->deleteCompanyOperation($companyId, $operationId);
        return ApiResponse::success(null, 'Deleted successfully.');
    }
}
