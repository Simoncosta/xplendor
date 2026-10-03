<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Recommendations\RecommendationEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * XPLENDOR — Recomendações (motor de regras explicáveis) de UMA empresa.
 * GET /companies/{id}/recommendations?vertical=restaurant|automotive
 */
class RecommendationController extends Controller
{
    public function __construct(private readonly RecommendationEngine $engine) {}

    public function index(Request $request, int $companyId)
    {
        // Tenant guard: só as recomendações da própria empresa (root vê qualquer uma).
        $user = Auth::user();
        if (! $user || ((int) $user->company_id !== $companyId && $user->role !== 'root')) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $vertical = (string) $request->query('vertical', 'restaurant');
        if (! in_array($vertical, ['restaurant', 'automotive'], true)) {
            return ApiResponse::error('Ramo inválido.', 422);
        }

        $company = Company::findOrFail($companyId);

        return ApiResponse::success(
            ['vertical' => $vertical] + $this->engine->forCompany($company, $vertical),
            'Recommendations fetched successfully.'
        );
    }
}
