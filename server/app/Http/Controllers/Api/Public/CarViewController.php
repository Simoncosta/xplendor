<?php

namespace App\Http\Controllers\Api\Public;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\CarViewRequest;
use App\Services\CarViewService;
use Illuminate\Http\Request;

class CarViewController extends Controller
{
    public function __construct(protected CarViewService $carViewService) {}

    public function store(CarViewRequest $request)
    {
        $data = $request->validated();
        $data['ip_address'] = $request->ip();
        $data['user_agent'] = $request->userAgent();
        $data['company_id'] = $request->input('public_api_company')->id;

        $response = $this->carViewService->store($data);

        return ApiResponse::success($response, 'Car view stored successfully.');
    }
}
