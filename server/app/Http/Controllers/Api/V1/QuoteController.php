<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\QuoteResource;
use App\Models\Quote;
use App\Services\QuoteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * XPLENDOR — Orçamentos, LADO DO STAND. Uma empresa vê APENAS os orçamentos
 * ligados a ela (company_id) e pode APROVAR/REJEITAR os que estão em validação.
 * Guard tenant de 2 camadas (padrão do projeto): o utilizador pertence à empresa
 * da rota (ou é root); o orçamento pertence mesmo a essa empresa (findScoped).
 *
 * O stand NUNCA vê orçamentos de nome livre (sem company_id) nem de outras
 * empresas. Marcar pago/concluído é exclusivo do super-admin (lado /admin).
 */
class QuoteController extends Controller
{
    public function __construct(protected QuoteService $service) {}

    private function authorizeCompanyAccess(int $companyId): bool
    {
        $user = Auth::user();

        return $user->company_id === $companyId || $user->role === 'root';
    }

    private function findScoped(int $companyId, int $id): ?Quote
    {
        return Quote::where('company_id', $companyId)->find($id);
    }

    public function index(int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $quotes = Quote::where('company_id', $companyId)->orderByDesc('id')->get();

        return ApiResponse::success(
            QuoteResource::collection($quotes)->resolve(),
            'Quotes fetched successfully.'
        );
    }

    /** A empresa aprova/rejeita um orçamento seu (só em validação). */
    public function decision(Request $request, int $companyId, int $id)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $quote = $this->findScoped($companyId, $id);
        if (! $quote) {
            return ApiResponse::error('Orçamento não encontrado.', 404);
        }

        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
        ]);

        $quote = $this->service->companyDecision($quote, $data['decision'] === 'approve');

        return ApiResponse::success(
            (new QuoteResource($quote))->resolve(),
            'Quote decision saved successfully.'
        );
    }
}
