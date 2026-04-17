<?php

namespace App\Http\Controllers\Api\Public;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\CarViewRequest;
use App\Models\Car;
use App\Services\AttributionService;
use App\Services\CarViewService;
use Illuminate\Http\Request;

class CarViewController extends Controller
{
    public function __construct(
        protected CarViewService $carViewService,
        protected AttributionService $attributionService,
    ) {}

    public function store(CarViewRequest $request)
    {
        $data = $request->validated();
        $data['ip_address'] = $request->ip();
        $data['user_agent'] = $request->userAgent();
        $data['company_id'] = $request->input('public_api_company')->id;

        $response = $this->carViewService->store($data);
        $car = Car::query()
            ->where('company_id', $data['company_id'])
            ->find($data['car_id']);

        if ($car) {
            $this->attributionService->trackVisit($car, $request);
        }

        return ApiResponse::success($response, 'Car view stored successfully.');
    }
}
