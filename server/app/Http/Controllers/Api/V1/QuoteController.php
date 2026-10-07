<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\QuoteResource;
use App\Models\Quote;
use App\Services\QuoteService;
use Illuminate\Http\Request;

/**
 * XPLENDOR — Orçamentos, LADO DA EMPRESA. Uma empresa vê APENAS os orçamentos
 * ligados a ela (company_id) que já lhe foram enviados, pode aceitar ou recusar os
 * que estão em aberto e descarregar o PDF da versão que recebeu.
 * Guard tenant de 2 camadas (padrão do projeto): o utilizador pertence à empresa
 * da rota (ou é root); o orçamento pertence mesmo a essa empresa (findScoped).
 *
 * O stand NUNCA vê orçamentos de nome livre (sem company_id) nem de outras
 * empresas, nem rascunhos.
 */
class QuoteController extends Controller
{
    public function __construct(protected QuoteService $service) {}

    private function authorizeCompanyAccess(int $companyId): bool
    {
        return $this->authorizeCompany($companyId);
    }

    private function findScoped(int $companyId, int $id): ?Quote
    {
        return Quote::where('company_id', $companyId)->where('status', '!=', 'draft')->find($id);
    }

    public function index(int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        // Rascunhos nunca chegam à empresa: só vê o que lhe foi enviado.
        $quotes = Quote::where('company_id', $companyId)->where('status', '!=', 'draft')->orderByDesc('id')->get();

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
        if ($this->viaAgency($companyId)) {
            return ApiResponse::error('As decisões sobre orçamentos são do cliente.', 403);
        }

        $quote = $this->findScoped($companyId, $id);
        if (! $quote) {
            return ApiResponse::error('Orçamento não encontrado.', 404);
        }

        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
        ]);

        $quote = $this->service->decide($quote, $data['decision'] === 'approve', byCompany: true);

        return ApiResponse::success(
            (new QuoteResource($quote))->resolve(),
            'Quote decision saved successfully.'
        );
    }

    /** PDF da última versão enviada (a que a empresa recebeu). */
    public function pdf(int $companyId, int $id)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $quote = $this->findScoped($companyId, $id);
        $version = $quote?->versions()->first();
        if (! $version) {
            return ApiResponse::error('Orçamento não encontrado.', 404);
        }

        return response($this->service->versionPdf($version), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $version->number . '-v' . $version->version . '.pdf"',
        ]);
    }
}
