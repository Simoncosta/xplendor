<?php

namespace App\Http\Controllers\Api\Public;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\CarLeadRequest;
use App\Models\Car;
use App\Services\AttributionService;
use App\Services\CarLeadService;
use Illuminate\Http\Request;

class CarLeadController extends Controller
{
    public function __construct(
        protected CarLeadService $carLeadService,
        protected AttributionService $attributionService,
    ) {}

    public function store(CarLeadRequest $request)
    {
        $data = $request->validated();
        $data['company_id'] = $request->input('public_api_company')->id;
        $response = $this->carLeadService->store($data);
        $car = Car::query()
            ->where('company_id', $data['company_id'])
            ->find($data['car_id']);

        if ($car) {
            $this->attributionService->trackLead($car, $request);
        }

        return ApiResponse::success($response, 'Car lead stored successfully.');
    }
}
