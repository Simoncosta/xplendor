<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateWeeklyMarketingIdeasJob;
use App\Services\CarMarketingIdeaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CarMarketingIdeaController extends Controller
{
    public function __construct(
        protected CarMarketingIdeaService $service
    ) {}

    public function index(Request $request, int $companyId): JsonResponse
    {
        $ideas = $this->service->getWeeklyIdeas($companyId);

        return ApiResponse::success($ideas, 'Weekly marketing ideas fetched successfully.');
    }

    public function generate(Request $request, int $companyId): JsonResponse
    {
        GenerateWeeklyMarketingIdeasJob::dispatch($companyId);

        return ApiResponse::success(null, 'Geração de ideias enviada para processamento.');
    }
}
