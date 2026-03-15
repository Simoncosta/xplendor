<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Jobs\CalculateCarSalePotentialScoreJob;
use App\Models\Car;
use App\Services\CarSalePotentialScoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CarSalePotentialScoreController extends Controller
{
    public function __construct(
        private CarSalePotentialScoreService $service
    ) {}

    // ── GET /companies/{id}/cars/{car}/potential-score ────────────────────────

    public function show(Request $request, int $company, int $car): JsonResponse
    {
        $this->authorizeAccess($car, $company);

        $data = $this->service->getLatestWithHistory($car, $company);

        if ($data['score'] === null) {
            return response()->json([
                'success' => false,
                'message' => 'Score ainda não calculado. Dispara um recálculo manual.',
            ], 404);
        }

        return ApiResponse::success($data, 'Score');
    }

    // ── POST /companies/{id}/cars/{car}/potential-score/recalculate ───────────

    public function recalculate(Request $request, int $company, int $car): JsonResponse
    {
        $this->authorizeAccess($car, $company);

        CalculateCarSalePotentialScoreJob::dispatch(
            carId: $car,
            companyId: $company,
            triggeredBy: 'manual',
        );

        return ApiResponse::success(null, 'Recálculo iniciado. O score estará disponível em segundos.');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function authorizeAccess(int $carId, int $companyId): void
    {
        abort_unless(
            Car::where('id', $carId)->where('company_id', $companyId)->exists(),
            403,
            'Acesso negado a esta viatura.'
        );
    }
}
