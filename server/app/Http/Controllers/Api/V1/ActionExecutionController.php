<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Car;
use App\Services\ActionExecution\ActionExecutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ActionExecutionController extends Controller
{
    public function __construct(
        protected ActionExecutionService $actionExecutionService,
    ) {}

    public function store(Request $request, int $companyId, int $carId): JsonResponse
    {
        if (!$this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $payload = $request->validate([
            'action' => 'required|string',
            'context' => 'nullable|array',
        ]);

        try {
            $car = $this->resolveCarForCompany($companyId, $carId);
            $result = $this->actionExecutionService->execute(
                $car,
                (string) $payload['action'],
                $payload['context'] ?? []
            );

            return ApiResponse::success($result, $result['message'] ?? 'Ação executada com sucesso.');
        } catch (\DomainException $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return ApiResponse::error('Não foi possível executar a ação pedida.', 500);
        }
    }

    private function authorizeCompanyAccess(int $companyId): bool
    {
        $user = Auth::user();

        return $user->company_id === $companyId || $user->role === 'root';
    }

    private function resolveCarForCompany(int $companyId, int $carId): Car
    {
        return Car::query()
            ->with([
                'brand:id,name',
                'model:id,name',
                'seller:id,name,mobile,whatsapp,company_id',
                'company:id,trade_name,fiscal_name,mobile,phone',
                'adCampaigns',
            ])
            ->where('company_id', $companyId)
            ->findOrFail($carId);
    }
}
