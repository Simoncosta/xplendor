<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\Ai\AiProviderException;
use App\Services\CollaboratorService;
use App\Services\PingwinService;
use App\Services\Restaurant\RestaurantDataQualityService;
use App\Services\Restaurant\RestaurantFamilyCategoryService;
use App\Services\Restaurant\RestaurantHeatmapService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — F1-3 do marketing da restauração (documents/PINGWIN-F1-DESENHO.md §5):
 *  · cartão "Dados para o marketing" nas Integrações (qualquer utilizador da empresa vê);
 *  · "Categorias das famílias": ver (qualquer utilizador), pedir sugestões à IA e
 *    confirmar (só quem configura integrações: administrador da empresa, administrador
 *    da agência gestora ou root). Nada fica confirmado sem uma pessoa;
 *  · postos de venda do relatório anual (início de cada loja): só o root;
 *  · F2: mapa de calor da semana por loja (qualquer utilizador da empresa).
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

        $isRoot = $this->isRoot($request);

        return ApiResponse::success($this->quality->card($companyId) + [
            'can_manage' => CollaboratorService::canConfigureIntegrations($request->user(), $companyId),
            // Postos de venda do relatório anual: só o root os vê e edita.
            'can_edit_locals' => $isRoot,
            'annual_locals' => $isRoot ? app(PingwinService::class)->annualLocals($companyId) : null,
        ], 'Dados para o marketing.');
    }

    // PUT /companies/{id}/integrations/pingwin/annual-locals   Body: { locals: "id,id" | "" } (só root)
    public function annualLocals(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId) || ! $this->isRoot($request)) {
            abort(403, 'Só o root define os postos de venda do relatório anual.');
        }
        $data = $request->validate([
            'locals' => ['present', 'nullable', 'string', 'max:500', 'regex:/^\s*(\d{1,20}\s*(,\s*\d{1,20}\s*)*)?$/'],
        ], ['locals.regex' => 'Indique os IDs dos postos de venda (só números), separados por vírgulas.']);

        $locals = implode(',', array_filter(array_map('trim', explode(',', (string) ($data['locals'] ?? ''))), fn ($v) => $v !== ''));
        app(PingwinService::class)->setAnnualLocals($companyId, $locals);
        Log::info('[PingWin] postos de venda do relatório anual alterados', ['company_id' => $companyId, 'user_id' => $request->user()->id]);

        return ApiResponse::success(['annual_locals' => $locals], $locals === '' ? 'Postos de venda limpos: o relatório anual soma todos.' : 'Postos de venda guardados.');
    }

    // GET /companies/{id}/integrations/pingwin/heatmap?location_id=&weeks=&exclude_special=   (F2, qualquer utilizador)
    public function heatmap(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $data = $request->validate([
            'location_id' => ['nullable', 'integer'],
            'weeks' => ['nullable', 'integer', 'min:4', 'max:26'],
            'exclude_special' => ['nullable', 'boolean'],
        ]);

        return ApiResponse::success(app(RestaurantHeatmapService::class)->build(
            $companyId,
            isset($data['location_id']) ? (int) $data['location_id'] : null,
            (int) ($data['weeks'] ?? RestaurantHeatmapService::DEFAULT_WEEKS),
            (bool) ($data['exclude_special'] ?? false),
        ), 'Mapa de calor da semana.');
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
        // Os sinais (e a Bússola) dependem das categorias: recalculam-se a seguir, fora do pedido.
        \App\Jobs\RecomputeRestaurantSignalsJob::dispatch($companyId)->afterCommit();

        return ApiResponse::success($this->categories->list($companyId) + ['can_manage' => true],
            $count === 1 ? 'Categoria confirmada.' : "{$count} categorias confirmadas.");
    }

    /** Regra da plataforma: os postos de venda do relatório anual são só do root. */
    private function isRoot(Request $request): bool
    {
        return $request->user()->role === 'root';
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
