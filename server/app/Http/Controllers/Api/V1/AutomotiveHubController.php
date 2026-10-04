<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Automotive\AutomotiveHubService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * XPLENDOR — Hub do Automóvel.
 *   GET /companies/{id}/automotive-hub          → resumo + recomendações (top 5) + avisos
 *   GET /companies/{id}/automotive-hub/funnel   → funil por viatura (?days=14|30&page=1&per_page=10)
 */
class AutomotiveHubController extends Controller
{
    public function __construct(private readonly AutomotiveHubService $hub) {}

    private function denied(int $companyId)
    {
        $user = Auth::user();
        if (! $user || ((int) $user->company_id !== $companyId && $user->role !== 'root')) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        return null;
    }

    public function index(int $companyId)
    {
        if ($r = $this->denied($companyId)) {
            return $r;
        }

        $company = Company::findOrFail($companyId);

        return ApiResponse::success([
            'summary' => $this->hub->summary($companyId),
            'recommendations' => $this->hub->recommendations($company),
            'warnings' => $this->hub->warnings($companyId),
        ]);
    }

    public function funnel(Request $request, int $companyId)
    {
        if ($r = $this->denied($companyId)) {
            return $r;
        }

        $days = (int) $request->query('days', 30);
        if (! in_array($days, AutomotiveHubService::FUNNEL_WINDOWS, true)) {
            return ApiResponse::error('Janela inválida: use 14 ou 30 dias.', 422);
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = (int) $request->query('per_page', AutomotiveHubService::FUNNEL_PER_PAGE);
        if ($perPage < 1 || $perPage > AutomotiveHubService::FUNNEL_MAX_PER_PAGE) {
            return ApiResponse::error('per_page inválido: entre 1 e ' . AutomotiveHubService::FUNNEL_MAX_PER_PAGE . '.', 422);
        }

        $sort = (string) $request->query('sort', AutomotiveHubService::FUNNEL_DEFAULT_SORT);
        if (! in_array($sort, AutomotiveHubService::FUNNEL_SORTS, true)) {
            return ApiResponse::error('sort inválido: use ' . implode(', ', AutomotiveHubService::FUNNEL_SORTS) . '.', 422);
        }

        $direction = (string) $request->query('direction', AutomotiveHubService::FUNNEL_DEFAULT_DIRECTION);
        if (! in_array($direction, ['asc', 'desc'], true)) {
            return ApiResponse::error('direction inválido: use asc ou desc.', 422);
        }

        return ApiResponse::success($this->hub->funnel($companyId, $days, null, $page, $perPage, $sort, $direction));
    }
}
