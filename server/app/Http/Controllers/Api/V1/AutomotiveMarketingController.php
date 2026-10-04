<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\Automotive\AutomotiveMarketingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/**
 * XPLENDOR — Dashboard do automóvel: bloco "Marketing e resultados" (GA4 + Meta +
 * leads) de um mês. Ver AutomotiveMarketingService. Só LEITURA.
 *
 * GET /companies/{id}/analytics/automotive/marketing?month=Y-m   (default: mês em curso)
 */
class AutomotiveMarketingController extends Controller
{
    public function __construct(private readonly AutomotiveMarketingService $service) {}

    public function show(Request $request, int $companyId)
    {
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

        return ApiResponse::success($this->service->build($companyId, $month), 'Automotive marketing block fetched successfully.');
    }
}
