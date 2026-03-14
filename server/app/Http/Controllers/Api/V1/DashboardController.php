<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        protected DashboardService $dashboardService
    ) {}

    public function index(Request $request, int $companyId)
    {
        $data = $this->dashboardService->getDashboard($companyId);

        return ApiResponse::success($data, 'Dashboard fetched successfully.');
    }
}
