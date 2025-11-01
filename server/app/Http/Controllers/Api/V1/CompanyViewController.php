<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCompanyViewRequest;
use App\Http\Resources\CompanyViewResource;
use App\Services\CompanyViewService;
use Illuminate\Http\Request;

class CompanyViewController extends Controller
{
    public function __construct(protected CompanyViewService $companyViewService) {}

    public function index()
    {
        $companyViews = $this->companyViewService->all();
        return ApiResponse::success(CompanyViewResource::collection($companyViews), 'Company view fetched successfully.');
    }

    public function store(StoreCompanyViewRequest $request)
    {
        $companyView = $this->companyViewService->store($request->validated());
        return ApiResponse::success(new CompanyViewResource($companyView), 'Company view created successfully.');
    }
}
