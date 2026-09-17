<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminStockCarResource;
use App\Services\AdminStockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * XPLENDOR — STOCK GLOBAL, lado ADMIN (super-admin / root). TRANSVERSAL: vê os
 * veículos de TODAS as empresas ATIVAS. Vive no grupo /api/v1/admin, atrás do
 * EnsureSuperAdmin — o único sítio com acesso "ver todas as empresas".
 *
 * Primeira consola de DADOS transversais (as anteriores — tickets/orçamentos —
 * eram de gestão). Defesa em profundidade: reconfirma role 'root'.
 * Os endpoints de stand (/companies/{id}/cars) ficam intactos e scoped.
 */
class StockController extends Controller
{
    public function __construct(protected AdminStockService $service) {}

    private function ensureRoot(): void
    {
        abort_unless(Auth::user()?->role === 'root', 403);
    }

    /** Listagem transversal (empresas ativas) + filtros + paginação. */
    public function index(Request $request)
    {
        $this->ensureRoot();

        $filters = [
            'company_id'   => $request->input('company_id'),
            'status'       => $request->input('status'),
            'car_brand_id' => $request->input('car_brand_id'),
            'vehicle_type' => $request->input('vehicle_type'),
            'search'       => $request->input('search'),
        ];

        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);
        $page    = max((int) $request->input('page', 1), 1);

        $paginator = $this->service->paginate($filters, $perPage, $page);

        return ApiResponse::success([
            'data' => AdminStockCarResource::collection($paginator->items())->resolve(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ], 'Global stock fetched successfully.');
    }

    /** Números do topo (embrião das métricas globais). */
    public function summary()
    {
        $this->ensureRoot();

        return ApiResponse::success($this->service->summary(), 'Global stock summary fetched successfully.');
    }

    /** Empresas ativas com stock — para o dropdown de filtro. */
    public function companies()
    {
        $this->ensureRoot();

        return ApiResponse::success($this->service->companiesWithStock(), 'Active companies fetched successfully.');
    }
}
