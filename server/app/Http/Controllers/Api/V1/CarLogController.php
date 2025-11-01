<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCarLogRequest;
use App\Http\Resources\CarLogResource;
use App\Services\CarLogService;
use Illuminate\Http\Request;

class CarLogController extends Controller
{
    public function __construct(protected CarLogService $carLogService) {}

    public function index()
    {
        $carLogs = $this->carLogService->all();
        return ApiResponse::success(CarLogResource::collection($carLogs), 'Car logs fetched successfully.');
    }

    public function store(StoreCarLogRequest $request)
    {
        $carLog = $this->carLogService->store($request->validated());
        return ApiResponse::success(new CarLogResource($carLog), 'Car log created successfully.');
    }
}
