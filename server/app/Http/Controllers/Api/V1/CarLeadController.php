<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCarLeadRequest;
use App\Http\Requests\UpdateCarLeadRequest;
use App\Http\Resources\CarLeadResource;
use App\Services\CarLeadService;
use Illuminate\Http\Request;

class CarLeadController extends Controller
{
    public function __construct(protected CarLeadService $carLeadService) {}

    public function index()
    {
        $carLeads = $this->carLeadService->all();
        return ApiResponse::success(CarLeadResource::collection($carLeads), 'Car leads fetched successfully.');
    }

    public function store(StoreCarLeadRequest $request)
    {
        $car = $this->carLeadService->store($request->validated());
        return ApiResponse::success(new CarLeadResource($car), 'Car lead created successfully.');
    }

    public function show(int $id)
    {
        $car = $this->carLeadService->find($id);
        return ApiResponse::success(new CarLeadResource($car), 'Car lead fetched successfully.');
    }
}
