<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\UserNotificationPreference;
use App\Services\AlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            (int) $request->query('limit', 20),
            $this->ownOnlyToo($companyId),
            $this->hiddenKinds(),
        );

        return ApiResponse::success($alerts, 'Alerts fetched successfully.');
    }

    public function unreadCount(int $companyId): JsonResponse
    {
        if (!$this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        return ApiResponse::success([
            'count' => $this->alertService->unreadCountForCompany($companyId, $this->ownOnlyToo($companyId), $this->hiddenKinds()),
        ], 'Unread alerts count fetched successfully.');
    }

    /** Bússola 7: as preferências de avisos de quem está ligado (por pessoa, em todas as empresas). */
    public function preferences(int $companyId): JsonResponse
    {
        if (!$this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        return ApiResponse::success(['radar_high_score' => UserNotificationPreference::radarHighScoreOn((int) request()->user()->id)], 'Preferências de avisos.');
    }

    public function updatePreferences(Request $request, int $companyId): JsonResponse
    {
        if (!$this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $data = $request->validate(['radar_high_score' => ['required', 'boolean']]);
        UserNotificationPreference::updateOrCreate(['user_id' => $request->user()->id], ['radar_high_score' => (bool) $data['radar_high_score']]);

        return ApiResponse::success(['radar_high_score' => (bool) $data['radar_high_score']],
            $data['radar_high_score'] ? 'Avisos de nota alta ligados.' : 'Avisos de nota alta desligados.');
    }

    /** @return list<string> */
    private function hiddenKinds(): array
    {
        return UserNotificationPreference::hiddenKinds(request()->user()?->id);
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

        $updated = $this->alertService->markAsRead($companyId, $payload['ids'] ?? [], $this->ownOnlyToo($companyId), $this->hiddenKinds());

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

    /** Quem entra pela agência gestora não vê os avisos só da própria empresa (ex.: pedidos de outras agências). */
    private function ownOnlyToo(int $companyId): bool
    {
        return ! app(\App\Services\Tenancy\CompanyAccess::class)->viaAgency(request()->user(), $companyId);
    }

    private function authorizeCompanyAccess(int $companyId): bool
    {
        return $this->authorizeCompany($companyId);
    }
}
