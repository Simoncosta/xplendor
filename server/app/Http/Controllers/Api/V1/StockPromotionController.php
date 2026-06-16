<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListPromotionCandidatesRequest;
use App\Http\Requests\StorePromotionPriorityRequest;
use App\Http\Resources\PromotionCandidatesCollection;
use App\Models\Car;
use App\Services\StockPromotionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Relatório A — candidatas a promoção.
 *
 * Multi-tenancy é o ponto-chave deste controller (sec 11 do CLAUDE.md):
 *   - admin → só vê/age sobre cars da SUA company
 *   - root  → vê/age sobre QUALQUER company (selector de company no UI)
 *   - tenant verification em TODOS os endpoints, dupla camada:
 *     1) `authorizeCompanyAccess($companyId)` — o user pode usar este companyId?
 *     2) no POST/DELETE também `$car->company_id === $companyId` — o car pertence?
 *
 * NÃO confiar no frontend para companyId — derivar do auth para admin é
 * impraticável aqui (root precisa de companyId no path para o selector),
 * mas o guard #1 rejeita um admin que tente forjar companyId no request.
 */
class StockPromotionController extends Controller
{
    public function __construct(
        private readonly StockPromotionService $service,
    ) {
    }

    /** GET /api/v1/companies/{companyId}/stock/promotion-candidates */
    public function index(ListPromotionCandidatesRequest $request, int $companyId): JsonResponse
    {
        if (!$this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado.', 403);
        }

        $perPage   = (int) ($request->input('per_page') ?? 25);
        $paginator = $this->service->getCandidates($companyId, $request->filters(), $perPage);

        return response()->json(
            (new PromotionCandidatesCollection($paginator))->response($request)->getData(true)
        );
    }

    /** GET /api/v1/companies/{companyId}/stock/promotion-candidates/summary */
    public function summary(int $companyId): JsonResponse
    {
        if (!$this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado.', 403);
        }

        return ApiResponse::success(
            $this->service->getSummary($companyId),
            'Summary fetched.'
        );
    }

    /** POST /api/v1/companies/{companyId}/stock/promotion-candidates/{carId} */
    public function store(
        StorePromotionPriorityRequest $request,
        int $companyId,
        int $carId
    ): JsonResponse {
        if (!$this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado.', 403);
        }

        // Defesa em profundidade: o car TEM de pertencer ao companyId do path.
        // Sem isto, um root com selector mal usado poderia marcar um car
        // de outra company por engano.
        $car = Car::find($carId);
        if (!$car || (int) $car->company_id !== $companyId) {
            return ApiResponse::error('Viatura não encontrada.', 404);
        }

        $userId   = Auth::id();
        $priority = $this->service->markForPromotion(
            $companyId,
            $carId,
            $userId,
            $request->input('note'),
        );

        return ApiResponse::success(
            [
                'id'         => $priority->id,
                'car_id'     => $priority->car_id,
                'marked_at'  => $priority->marked_at?->toIso8601String(),
                'note'       => $priority->note,
                'marked_by'  => $userId ? ['id' => $userId, 'name' => Auth::user()->name] : null,
            ],
            'Prioridade marcada.',
            201
        );
    }

    /** DELETE /api/v1/companies/{companyId}/stock/promotion-candidates/{carId} */
    public function destroy(int $companyId, int $carId): JsonResponse
    {
        if (!$this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado.', 403);
        }

        $car = Car::find($carId);
        if (!$car || (int) $car->company_id !== $companyId) {
            return ApiResponse::error('Viatura não encontrada.', 404);
        }

        // Idempotente: já não-marcada devolve 200 com flag — não 500/404.
        $unmarked = $this->service->unmarkPromotion($companyId, $carId, Auth::id());

        return ApiResponse::success(
            ['was_active' => $unmarked],
            $unmarked ? 'Prioridade removida.' : 'Nada para remover.'
        );
    }

    // ── Tenant guard ──────────────────────────────────────────────────────

    /**
     * Padrão dos restantes controllers v1 (CarController, AlertController, …):
     * admin → só a sua company; root → qualquer.
     *
     * Função principal: impedir que um admin do stand A consiga aceder ao
     * stand B forjando o `companyId` no path (sec 11 do CLAUDE.md).
     */
    private function authorizeCompanyAccess(int $companyId): bool
    {
        $user = Auth::user();
        if (!$user) {
            return false;
        }
        return (int) $user->company_id === $companyId || $user->role === 'root';
    }
}
