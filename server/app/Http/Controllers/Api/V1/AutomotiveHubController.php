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
 *   GET /companies/{id}/automotive-hub/funnel   → funil por viatura (?days=14|30)
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

        return ApiResponse::success($this->hub->funnel($companyId, $days));
    }
}
