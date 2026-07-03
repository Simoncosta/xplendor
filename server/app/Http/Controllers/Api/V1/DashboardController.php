<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\GetSalesRevenueRequest;
use App\Http\Resources\SalesRevenueResource;
use App\Http\Resources\StockBreakdownResource;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function __construct(
        protected DashboardService $dashboardService
    ) {}

    public function index(Request $request, int $companyId)
    {
        $data = $this->dashboardService->getDashboard($companyId);

        return ApiResponse::success($data, 'Dashboard fetched successfully.');
    }

    /**
     * Visões 1+2 do Dashboard (2026-06-25) — stock por marca + tipo.
     *
     * Endpoint separado do `index` para evitar inflar o blob das 18 chaves
     * (dívida 9). Tenant guard: company_id derivada do auth (sec 11) — admin
     * só vê a própria; root vê qualquer.
     */
    public function stockBreakdown(Request $request, int $companyId)
    {
        $user = Auth::user();
        if (!$user || ((int) $user->company_id !== $companyId && $user->role !== 'root')) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $data = $this->dashboardService->getStockBreakdown($companyId);

        return ApiResponse::success(new StockBreakdownResource($data), 'Stock breakdown fetched successfully.');
    }

    /**
     * Visão 3 do Dashboard (2026-06-25) — **FATURAÇÃO** (valor das vendas)
     * por período. NÃO É LUCRO — sem `purchase_price` em prod.
     *
     * Form Request valida o range (`from`/`to` Y-m-d, `to >= from`,
     * granularity enum). Mesmo tenant guard do `stockBreakdown`.
     */
    public function salesRevenue(GetSalesRevenueRequest $request, int $companyId)
    {
        $user = Auth::user();
        if (!$user || ((int) $user->company_id !== $companyId && $user->role !== 'root')) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $validated = $request->validated();
        $data = $this->dashboardService->getSalesRevenue(
            $companyId,
            $validated['from'],
            $validated['to'],
            $validated['granularity'] ?? 'month',
        );

        return ApiResponse::success(new SalesRevenueResource($data), 'Sales revenue fetched successfully.');
    }
}
