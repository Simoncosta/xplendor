<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\AlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AlertController extends Controller
{
    public function __construct(
        protected AlertService $alertService,
    ) {}

    public function index(Request $request, int $companyId): JsonResponse
    {
        if (!$this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $alerts = $this->alertService->getRecentForCompany(
            $companyId,
            filter_var($request->query('unread_only', false), FILTER_VALIDATE_BOOL),
            (int) $request->query('limit', 20)
        );

        return ApiResponse::success($alerts, 'Alerts fetched successfully.');
    }

    public function unreadCount(int $companyId): JsonResponse
    {
        if (!$this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        return ApiResponse::success([
            'count' => $this->alertService->unreadCountForCompany($companyId),
        ], 'Unread alerts count fetched successfully.');
    }

    public function markRead(Request $request, int $companyId): JsonResponse
    {
        if (!$this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $payload = $request->validate([
            'ids' => 'nullable|array',
            'ids.*' => 'integer',
        ]);

        $updated = $this->alertService->markAsRead($companyId, $payload['ids'] ?? []);

        return ApiResponse::success([
            'updated' => $updated,
        ], 'Alerts marked as read successfully.');
    }

    public function markOneRead(int $companyId, int $alertId): JsonResponse
    {
        if (!$this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        return ApiResponse::success([
            'updated' => $this->alertService->markOneAsRead($companyId, $alertId),
        ], 'Alert marked as read successfully.');
    }

    private function authorizeCompanyAccess(int $companyId): bool
    {
        $user = Auth::user();

        return $user->company_id === $companyId || $user->role === 'root';
    }
}
