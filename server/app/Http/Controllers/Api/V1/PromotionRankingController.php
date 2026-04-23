<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\NextBestCarToPromoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PromotionRankingController extends Controller
{
    public function __construct(
        protected NextBestCarToPromoteService $nextBestCarToPromoteService
    ) {}

    public function index(Request $request, int $companyId): JsonResponse
    {
        $user = Auth::user();

        if (!$user || ($user->company_id !== $companyId && $user->role !== 'root')) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $payload = $this->nextBestCarToPromoteService->rankCarsForPromotion($companyId, [
            'limit' => $request->query('limit', 'all'),
        ]);

        return ApiResponse::success($payload, 'Promotion ranking fetched successfully.');
    }
}
