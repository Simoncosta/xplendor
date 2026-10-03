<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\RestaurantMarketingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/**
 * XPLENDOR — Dashboard de restauração: bloco "marketing e resultados" (GA4 + Meta +
 * dados internos) de um mês. Ver RestaurantMarketingService. Só LEITURA.
 *
 * GET /companies/{id}/analytics/restaurant/marketing?month=Y-m   (default: mês em curso)
 */
class RestaurantMarketingController extends Controller
{
    public function __construct(private readonly RestaurantMarketingService $service) {}

    public function show(Request $request, int $companyId)
    {
        // Tenant guard (lição do achado de segurança no dashboard): o utilizador só
        // vê a SUA empresa; root vê qualquer uma. Em impersonation o token é do
        // utilizador-alvo → fica limitado à empresa desse utilizador.
        $user = Auth::user();
        if (! $user || ((int) $user->company_id !== $companyId && $user->role !== 'root')) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $month = $request->query('month');
        if ($month !== null) {
            $v = Validator::make(['month' => $month], ['month' => ['date_format:Y-m']]);
            if ($v->fails()) {
                return ApiResponse::error('Mês inválido (formato AAAA-MM).', 422, $v->errors()->toArray());
            }
            if (CarbonImmutable::createFromFormat('Y-m-d', $month . '-01')->gt(CarbonImmutable::today()->startOfMonth())) {
                return ApiResponse::error('Não é possível analisar um mês futuro.', 422);
            }
        }

        return ApiResponse::success($this->service->build($companyId, $month), 'Restaurant marketing block fetched successfully.');
    }
}
