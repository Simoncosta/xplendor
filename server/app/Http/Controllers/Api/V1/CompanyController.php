<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCompanyRequest;
use App\Http\Requests\UpdateCompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Services\CompanyService;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    public function __construct(protected CompanyService $companyService) {}

    public function index()
    {
        $companies = $this->companyService->all();
        return ApiResponse::success(CompanyResource::collection($companies), 'Companies fetched successfully.');
    }

    public function store(StoreCompanyRequest $request)
    {
        $company = $this->companyService->store($request->all());
        return ApiResponse::success(new CompanyResource($company), 'Company created successfully.', 201);
    }

    public function show(int $id)
    {
        $company = $this->companyService->findWithRelations($id, ['plan', 'country', 'operations']);
        return ApiResponse::success(new CompanyResource($company), 'Company fetched successfully.');
    }

    public function update(UpdateCompanyRequest $request, int $id)
    {
        $company = $this->companyService->update($id, $request->all());
        return ApiResponse::success(new CompanyResource($company), 'Company updated successfully.');
    }

    public function destroy(int $id)
    {
        $this->companyService->delete($id);
        return ApiResponse::success(null, 'Company deleted successfully.');
    }
}
