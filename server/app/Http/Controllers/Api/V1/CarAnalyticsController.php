<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Repositories\Contracts\CarRepositoryInterface;
use App\Services\CarAnalyticsService;
use Illuminate\Http\Request;

class CarAnalyticsController extends Controller
{
    public function __construct(
        protected CarAnalyticsService $analyticsService,
        // Other services
        protected CarRepositoryInterface $carRepository,
    ) {}

    public function show(int $companyId, int $carId)
    {
        $car = $this->carRepository->findOrFail($carId, 'id');
        $analytics = $this->analyticsService->show($car);

        return ApiResponse::success($analytics, 'Car analytics fetched successfully.');
    }
}
