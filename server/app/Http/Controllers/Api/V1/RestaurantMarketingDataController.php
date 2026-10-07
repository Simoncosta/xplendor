<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\Ai\AiProviderException;
use App\Services\CollaboratorService;
use App\Services\Restaurant\RestaurantDataQualityService;
use App\Services\Restaurant\RestaurantFamilyCategoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — F1-3 do marketing da restauração (documents/PINGWIN-F1-DESENHO.md §5):
 *  · cartão "Dados para o marketing" nas Integrações (qualquer utilizador da empresa vê);
 *  · "Categorias das famílias": ver (qualquer utilizador), pedir sugestões à IA e
 *    confirmar (só quem configura integrações: administrador da empresa, administrador
 *    da agência gestora ou root). Nada fica confirmado sem uma pessoa.
 */
class RestaurantMarketingDataController extends Controller
{
    public function __construct(
        private readonly RestaurantDataQualityService $quality,
        private readonly RestaurantFamilyCategoryService $categories,
    ) {}

    // GET /companies/{id}/integrations/pingwin/marketing-data
    public function show(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        return ApiResponse::success($this->quality->card($companyId) + [
            'can_manage' => CollaboratorService::canConfigureIntegrations($request->user(), $companyId),
        ], 'Dados para o marketing.');
    }

    // GET /companies/{id}/integrations/pingwin/family-categories
    public function families(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        return ApiResponse::success($this->categories->list($companyId) + [
            'can_manage' => CollaboratorService::canConfigureIntegrations($request->user(), $companyId),
        ], 'Categorias das famílias.');
    }

    // POST /companies/{id}/integrations/pingwin/family-categories/ai-suggest
    public function suggest(Request $request, int $companyId)
    {
        $this->assertCanManage($request, $companyId);
        try {
            $count = $this->categories->suggestWithAi($companyId, $request->user()->id);
        } catch (AiProviderException $e) {
            Log::warning('[Categorias das famílias] IA falhou', ['company_id' => $companyId, 'error' => $e->getMessage()]);

            return ApiResponse::error('A IA não respondeu. Tente novamente dentro de alguns minutos.', 502);
        }

        return ApiResponse::success($this->categories->list($companyId) + ['can_manage' => true, 'suggested' => $count],
            $count > 0 ? "A IA sugeriu {$count} categoria(s). Confirme-as antes de contarem." : 'Não há famílias sem sugestão.');
    }

    // PUT /companies/{id}/integrations/pingwin/family-categories   Body: { items: [{ family_pingwin_id, category }] }
    public function confirm(Request $request, int $companyId)
    {
        $this->assertCanManage($request, $companyId);
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.family_pingwin_id' => ['required', 'string', 'max:32'],
            'items.*.category' => ['required', 'string', 'max:32'],
        ]);

        $count = $this->categories->confirm($companyId, $data['items'], $request->user());

        return ApiResponse::success($this->categories->list($companyId) + ['can_manage' => true],
            $count === 1 ? 'Categoria confirmada.' : "{$count} categorias confirmadas.");
    }

    private function assertCanManage(Request $request, int $companyId): void
    {
        if (! $this->authorizeCompany($companyId)) {
            abort(403, 'Acesso negado: utilizador inválido.');
        }
        if (! CollaboratorService::canConfigureIntegrations($request->user(), $companyId)) {
            abort(403, 'Só o administrador da empresa ou um administrador da agência gestora pode confirmar as categorias.');
        }
    }
}
