<?php

namespace App\Http\Controllers\Api\Public;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\CarLeadRequest;
use App\Services\CarLeadService;
use Illuminate\Http\Request;

class CarLeadController extends Controller
{
    public function __construct(protected CarLeadService $carLeadService) {}

    public function store(CarLeadRequest $request)
    {
        $data = $request->validated();
        $data['company_id'] = $request->input('public_api_company')->id;
        $response = $this->carLeadService->store($data);

        return ApiResponse::success($response, 'Car lead stored successfully.');
    }
}
