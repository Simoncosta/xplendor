<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCarPerformanceMetricRequest;
use App\Http\Requests\UpdateCarPerformanceMetricRequest;
use App\Services\CarPerformanceMetricService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CarPerformanceMetricController extends Controller
{
    public function __construct(
        private CarPerformanceMetricService $performanceService
    ) {}

    public function index(Request $request, int $companyId, int $car): JsonResponse
    {
        $metrics = $this->performanceService->getForCar(
            carId: $car,
            companyId: $companyId,
            channel: $request->query('channel'),
            from: $request->query('from'),
            to: $request->query('to'),
        );

        return ApiResponse::success($metrics, 'Métricas fetched successfully.');
    }

    public function summary(Request $request, int $companyId, int $car): JsonResponse
    {
        $data = $this->performanceService->getSummary($car, $companyId);

        return ApiResponse::success($data, 'Métricas fetched successfully.');
    }

    public function store(StoreCarPerformanceMetricRequest $request, int $companyId, int $car): JsonResponse
    {
        $metric = $this->performanceService->create(
            carId: $car,
            companyId: $companyId,
            data: $request->validated(),
        );

        return ApiResponse::success($metric->refresh(), 'Métricas registadas com sucesso.');
    }

    public function update(UpdateCarPerformanceMetricRequest $request, int $companyId, int $car, int $metric): JsonResponse
    {
        $updated = $this->performanceService->update(
            metricId: $metric,
            carId: $car,
            companyId: $companyId,
            data: $request->validated(),
        );

        return ApiResponse::success($updated, 'Métricas atualizadas com sucesso.');
    }

    private function companyId(Request $request): int
    {
        return (int) $request->user()->company_id;
    }
}
