<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Access\Access;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\CarLead;
use App\Models\FinancingDocument;
use App\Models\FinancingLink;
use App\Models\FinancingRequest;
use App\Services\Financing\FinancingDocumentTypes;
use App\Services\Financing\FinancingLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A retoma de uma lead (o "Pedir proposta" do site com retoma), no detalhe da lead: os dados da
 * viatura (marca, modelo, ano, km), as observações, as fotografias e o estado dos links.
 * As fotografias, os km e as observações seguem a lead (automovel.ver para ver;
 * automovel.editar para pedir, revogar e apagar). O DUA tem o nome e a morada do proprietário:
 * segue a área "Financiamento" (só aparece aqui a quem a tem, e descarrega-se pela rota dos
 * documentos de financiamento, que regista cada descarga).
 */
class TradeInController extends Controller
{
    public function __construct(private readonly FinancingLinkService $links, private readonly Access $access) {}

    // GET /companies/{id}/leads/{lead}/trade-in
    public function show(Request $request, int $companyId, int $leadId): JsonResponse
    {
        $lead = CarLead::where('company_id', $companyId)->findOrFail($leadId);
        $user = $request->user();
        $can = fn (string $p) => $this->access->can($user, $companyId, $p)->allowed;
        $personal = $can('financiamento.ver');

        return ApiResponse::success([
            'trade_in' => $lead->trade_in,
            'requests' => $this->links->forLead($companyId, $lead->id, FinancingRequest::TRADE_IN)->map(fn (FinancingRequest $r) => $this->links->present($r, $personal))->values(),
            'can' => ['request' => $can('automovel.editar'), 'delete' => $can('automovel.editar'), 'see_dua' => $personal, 'delete_dua' => $can('financiamento.apagar')],
            'defaults' => ['days' => (int) config('financing.link_days_default'), 'max_days' => (int) config('financing.link_days_max')],
            'antivirus_blocks' => $this->links->antivirusBlocks(),
        ], 'Retoma.');
    }

    // POST /companies/{id}/leads/{lead}/trade-in/link   { days? }
    public function store(Request $request, int $companyId, int $leadId): JsonResponse
    {
        $lead = CarLead::where('company_id', $companyId)->findOrFail($leadId);
        $data = $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:' . config('financing.link_days_max')]]);
        $fin = $this->links->createTradeIn($lead, $request->user(), now()->addDays((int) ($data['days'] ?? config('financing.link_days_default')))->endOfDay());
        $link = $fin->links()->first();

        return ApiResponse::success(['url' => $link->url(), 'expires_at' => $link->expires_at->toIso8601String()], 'Link da retoma criado.', 201);
    }

    // GET /companies/{id}/trade-in-links/{link}/url
    public function url(int $companyId, int $linkId): JsonResponse
    {
        $link = $this->tradeInLink($companyId, $linkId);
        abort_unless($link->isOpen(), 422, 'Este link já não está ativo.');

        return ApiResponse::success(['url' => $link->url()], 'Link.');
    }

    // POST /companies/{id}/trade-in-links/{link}/revoke
    public function revoke(Request $request, int $companyId, int $linkId): JsonResponse
    {
        $this->links->revoke($request->user(), $this->tradeInLink($companyId, $linkId));

        return ApiResponse::success(null, 'Link revogado.');
    }

    // GET /companies/{id}/trade-in-files/{document}/download   (fotografias; o DUA não)
    public function download(Request $request, int $companyId, int $documentId): JsonResponse
    {
        return ApiResponse::success(['url' => $this->links->downloadUrl($request->user(), $this->photo($companyId, $documentId), log: false)], 'Endereço de descarga.');
    }

    // DELETE /companies/{id}/trade-in-files/{document}
    public function destroy(int $companyId, int $documentId): JsonResponse
    {
        $this->links->deleteDocument($this->photo($companyId, $documentId));

        return ApiResponse::success(null, 'Fotografia apagada.');
    }

    private function tradeInLink(int $companyId, int $linkId): FinancingLink
    {
        $link = FinancingLink::with('request')->where('company_id', $companyId)->findOrFail($linkId);
        abort_if(! $link->request || ! $link->request->isTradeIn(), 404);

        return $link;
    }

    /** Uma fotografia de uma retoma desta empresa (o DUA trata-se pela rota dos documentos de financiamento: 404 aqui). */
    private function photo(int $companyId, int $documentId): FinancingDocument
    {
        $doc = FinancingDocument::with(['storedFile', 'link.request'])->where('company_id', $companyId)->findOrFail($documentId);
        abort_if(! $doc->link?->request?->isTradeIn() || in_array($doc->kind, FinancingDocumentTypes::PERSONAL, true), 404);

        return $doc;
    }
}
