<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Car;
use App\Services\CarDecisionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CarDecisionController extends Controller
{
    public function __construct(
        protected CarDecisionService $carDecisionService,
    ) {}

    public function index(Request $request, int $companyId): JsonResponse
    {
        if (!$this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $cars = Car::query()
            ->with(['brand:id,name', 'model:id,name'])
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->orderByDesc('created_at')
            ->get();

        $payload = $this->carDecisionService->resolveForCars(
            $cars,
            $request->query('from'),
            $request->query('to')
        );

        return ApiResponse::success($payload, 'Decisions fetched successfully.');
    }

    public function show(Request $request, int $companyId, int $carId): JsonResponse
    {
        if (!$this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $car = $this->resolveCarForCompany($companyId, $carId);

        $payload = $this->carDecisionService->resolve(
            $car,
            $request->query('from'),
            $request->query('to')
        );

        return ApiResponse::success($payload, 'Decision fetched successfully.');
    }

    private function authorizeCompanyAccess(int $companyId): bool
    {
        $user = Auth::user();

        return $user->company_id === $companyId || $user->role === 'root';
    }

    private function resolveCarForCompany(int $companyId, int $carId): Car
    {
        return Car::query()
            ->with(['brand:id,name', 'model:id,name'])
            ->where('company_id', $companyId)
            ->findOrFail($carId);
    }
}
