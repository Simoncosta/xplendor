<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCarViewRequest;
use App\Http\Resources\CarViewResource;
use App\Services\CarViewService;
use Illuminate\Http\Request;

class CarViewController extends Controller
{
    public function __construct(protected CarViewService $carViewService) {}

    public function index()
    {
        $carViews = $this->carViewService->all();
        return ApiResponse::success(CarViewResource::collection($carViews), 'Car views fetched successfully.');
    }

    public function store(StoreCarViewRequest $request)
    {
        $carView = $this->carViewService->store($request->validated());
        return ApiResponse::success(new CarViewResource($carView), 'Car view created successfully.');
    }
}
