<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateWeeklyMarketingIdeasJob;
use App\Services\CarMarketingIdeaService;
use App\Services\CarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CarMarketingIdeaController extends Controller
{
    public function __construct(
        protected CarMarketingIdeaService $service,
        // Other services
        protected CarService $carService
    ) {}

    public function index(Request $request, int $companyId): JsonResponse
    {
        $ideas = $this->service->getWeeklyIdeas($companyId);

        return ApiResponse::success($ideas, 'Weekly marketing ideas fetched successfully.');
    }

    public function show(Request $request, int $companyId, int $carId): JsonResponse
    {
        $carMarketing = $this->carService->findOrFail(
            $carId,
            'id',
            [
                'id',
                'company_id',
                'status',
                'license_plate',
                'version',
                'segment',
                'fuel_type',
                'price_gross',
                'car_brand_id',
                'car_model_id',
                'created_at',
            ],
            [
                'brand:id,name',
                'model:id,name',
                'marketingIdeas' => function ($query) {
                    $query->select([
                        'id',
                        'car_id',
                        'company_id',
                        'content_type',
                        'week_ref',
                        'status',
                        'title',
                        'angle',
                        'goal',
                        'target_audience',
                        'formats',
                        'primary_texts',
                        'headlines',
                        'descriptions',
                        'caption',
                        'hooks',
                        'cta',
                        'content_pillars',
                        'why_now',
                        'created_at',
                    ])->latest();
                },
            ]
        );

        $carMarketing->views_count        = $carMarketing->views()->count();
        $carMarketing->leads_count        = $carMarketing->leads()->count();
        $carMarketing->interactions_count = $carMarketing->interactions()->count();
        $carMarketing->days_in_stock      = (int) $carMarketing->created_at->diffInDays(now());

        // IPS mais recente (null se ainda não calculado)
        $latestIps = \App\Models\CarSalePotentialScore::where('car_id', $carId)
            ->where('company_id', $companyId)
            ->latest('calculated_at')
            ->first(['score', 'classification', 'calculated_at']);

        $carMarketing->ips = $latestIps
            ? [
                'score'          => $latestIps->score,
                'classification' => $latestIps->classification,
                'calculated_at'  => $latestIps->calculated_at,
            ]
            : null;

        return ApiResponse::success($carMarketing, 'Car marketing fetched successfully.');
    }

    public function generate(Request $request, int $companyId): JsonResponse
    {
        $validated = $request->validate([
            'car_id' => ['nullable', 'integer'],
        ]);

        GenerateWeeklyMarketingIdeasJob::dispatch($companyId, $validated['car_id'] ?? null);

        return ApiResponse::success(null, 'Geração de ideias enviada para processamento.');
    }
}
