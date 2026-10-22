<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Access\Access;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Car;
use App\Models\CarLead;
use App\Models\Company;
use App\Models\FinancingDocument;
use App\Models\FinancingLink;
use App\Models\FinancingRequest;
use App\Services\Financing\FinancingAudit;
use App\Services\Financing\FinancingDocumentTypes;
use App\Services\Financing\FinancingLinkService;
use App\Services\Financing\RetentionDays;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pedidos de documentos para financiamento, no painel (área "financiamento"; as permissões de
 * cada rota estão em RoutePermissions). Cada pedido junta 1 ou 2 titulares, cada um com o seu
 * link. Os pedidos e os documentos só da empresa da rota (de outra empresa: 404). O DUA da retoma
 * descarrega-se e apaga-se por aqui (segue a área "Financiamento"); as fotografias da retoma, na
 * lead. Os acessos do root a outra empresa e cada descarga ficam registados.
 */
class FinancingLinkController extends Controller
{
    public function __construct(private readonly FinancingLinkService $links, private readonly Access $access) {}

    // GET /companies/{id}/cars/{carId}/financing-links
    public function carIndex(Request $request, int $companyId, int $carId): JsonResponse
    {
        $car = Car::where('company_id', $companyId)->findOrFail($carId);
        FinancingAudit::rootAccess($request->user(), $companyId, 'ver_pedidos_viatura', Car::class, $car->id);

        return $this->listing($request, $companyId, $this->links->forCar($companyId, $car->id));
    }

    // POST /companies/{id}/cars/{carId}/financing-links
    public function carStore(Request $request, int $companyId, int $carId): JsonResponse
    {
        return $this->store($request, Car::where('company_id', $companyId)->findOrFail($carId), null);
    }

    // GET /companies/{id}/leads/{lead}/financing-links
    public function leadIndex(Request $request, int $companyId, int $leadId): JsonResponse
    {
        $lead = CarLead::where('company_id', $companyId)->findOrFail($leadId);
        FinancingAudit::rootAccess($request->user(), $companyId, 'ver_pedidos_lead', CarLead::class, $lead->id);

        return $this->listing($request, $companyId, $this->links->forLead($companyId, $lead->id));
    }

    // POST /companies/{id}/leads/{lead}/financing-links
    public function leadStore(Request $request, int $companyId, int $leadId): JsonResponse
    {
        $lead = CarLead::where('company_id', $companyId)->findOrFail($leadId);

        return $this->store($request, Car::where('company_id', $companyId)->findOrFail((int) $lead->car_id), $lead);
    }

    // POST /companies/{id}/financing-requests/{request}/second-holder   { customer_name?, relationship?, relationship_other? }
    public function secondHolder(Request $request, int $companyId, int $requestId): JsonResponse
    {
        $fin = $this->financingRequest($companyId, $requestId);
        $data = $request->validate([
            'customer_name' => ['nullable', 'string', 'max:120'],
            'relationship' => ['nullable', Rule::in(array_keys(FinancingLink::RELATIONSHIPS))],
            'relationship_other' => ['nullable', 'required_if:relationship,outro', 'string', 'max:60'],
        ]);
        $link = $this->links->addSecondHolder($fin, $data['customer_name'] ?? null, $data['relationship'] ?? null, $data['relationship_other'] ?? null);

        return ApiResponse::success($this->presentFresh($fin) + ['url' => $link->url()], 'Segundo titular acrescentado: copie o link e envie-lho.', 201);
    }

    // DELETE /companies/{id}/financing-requests/{request}
    public function destroyRequest(int $companyId, int $requestId): JsonResponse
    {
        $this->links->deleteRequest($this->financingRequest($companyId, $requestId));

        return ApiResponse::success(null, 'Pedido e documentos apagados.');
    }

    // GET /companies/{id}/financing-links/{link}/url   (voltar a copiar o link)
    public function url(int $companyId, int $linkId): JsonResponse
    {
        $link = $this->financingLink($companyId, $linkId);
        abort_unless($link->isOpen(), 422, 'Este link já não está ativo.');

        return ApiResponse::success(['url' => $link->url()], 'Link.');
    }

    // POST /companies/{id}/financing-links/{link}/revoke
    public function revoke(Request $request, int $companyId, int $linkId): JsonResponse
    {
        $link = $this->financingLink($companyId, $linkId);
        $this->links->revoke($request->user(), $link);

        return ApiResponse::success($this->presentFresh($link->request), 'Link revogado: o titular já não o pode usar.');
    }

    // DELETE /companies/{id}/financing-links/{link}   (um titular; se era o único, o pedido)
    public function destroy(int $companyId, int $linkId): JsonResponse
    {
        $this->links->deleteLink($this->financingLink($companyId, $linkId));

        return ApiResponse::success(null, 'Titular e documentos apagados.');
    }

    // GET /companies/{id}/financing-documents/{document}/download   (documentos de financiamento e DUA)
    public function download(Request $request, int $companyId, int $documentId): JsonResponse
    {
        return ApiResponse::success(['url' => $this->links->downloadUrl($request->user(), $this->financingDocument($companyId, $documentId))], 'Endereço de descarga.');
    }

    // DELETE /companies/{id}/financing-documents/{document}
    public function destroyDocument(int $companyId, int $documentId): JsonResponse
    {
        $this->links->deleteDocument($this->financingDocument($companyId, $documentId));

        return ApiResponse::success(null, 'Documento apagado.');
    }

    // GET /companies/{id}/financing-settings
    public function settings(Request $request, int $companyId): JsonResponse
    {
        return ApiResponse::success($this->settingsPayload($request, $companyId), 'Definições.');
    }

    // PUT /companies/{id}/financing-settings   { retention_days, default_items? }
    public function updateSettings(Request $request, int $companyId): JsonResponse
    {
        $data = $request->validate([
            'retention_days' => ['required', 'integer', 'min:' . config('financing.retention_days_min'), 'max:' . config('financing.retention_days_max')],
            'default_items' => ['sometimes', 'array', 'min:1'],
            'default_items.*' => ['string', Rule::in(array_diff(FinancingDocumentTypes::offered(FinancingRequest::FINANCING), ['outro']))],
        ], ['default_items.min' => 'Escolha pelo menos um documento para a lista por omissão.']);
        $update = ['financing_retention_days' => (int) $data['retention_days']];
        if (array_key_exists('default_items', $data)) {
            $update['financing_default_items'] = json_encode(array_values(array_unique($data['default_items'])));
        }
        Company::whereKey($companyId)->update($update);

        return ApiResponse::success($this->settingsPayload($request, $companyId), 'Definições guardadas.');
    }

    private function store(Request $request, Car $car, ?CarLead $lead): JsonResponse
    {
        $year = (int) now('Europe/Lisbon')->format('Y');
        $data = $request->validate([
            'customer_name' => ['required', 'string', 'min:2', 'max:120'],
            'holders' => ['nullable', 'integer', Rule::in([1, 2])],
            'second_customer_name' => ['nullable', 'string', 'max:120'],
            'relationship' => ['nullable', Rule::in(array_keys(FinancingLink::RELATIONSHIPS))],
            'relationship_other' => ['nullable', 'required_if:relationship,outro', 'string', 'max:60'],
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['string', Rule::in(FinancingDocumentTypes::offered(FinancingRequest::FINANCING))],
            'other' => [Rule::requiredIf(in_array('outro', (array) $request->input('items', []), true)), 'nullable', 'string', 'max:120'],
            'irs_year' => ['nullable', 'integer', 'min:' . ($year - 6), 'max:' . $year],
            'days' => ['nullable', 'integer', 'min:1', 'max:' . config('financing.link_days_max')],
        ], [
            'items.required' => 'Escolha pelo menos um documento.',
            'other.required' => 'Descreva o outro documento.',
            'relationship_other.required_if' => 'Indique qual é a relação.',
        ]);
        $fin = $this->links->createRequest($request->user(), $car, $lead, $data);
        $fin->load('links');

        return ApiResponse::success($this->presentFresh($fin) + ['urls' => $fin->links->mapWithKeys(fn (FinancingLink $l) => [$l->holder => $l->url()])->all()], 'Pedido criado.', 201);
    }

    private function listing(Request $request, int $companyId, $requests): JsonResponse
    {
        return ApiResponse::success([
            'requests' => $requests->map(fn (FinancingRequest $r) => $this->links->present($r))->values(),
        ] + $this->settingsPayload($request, $companyId), 'Pedidos de documentos.');
    }

    private function presentFresh(FinancingRequest $request): array
    {
        return $this->links->present($request->fresh(['links.documents.storedFile', 'links.consents', 'creator:id,name', 'company']));
    }

    private function financingRequest(int $companyId, int $requestId): FinancingRequest
    {
        return FinancingRequest::where('company_id', $companyId)->where('purpose', FinancingRequest::FINANCING)->findOrFail($requestId);
    }

    /** Um link de financiamento desta empresa (os da retoma tratam-se na lead: 404 aqui). */
    private function financingLink(int $companyId, int $linkId): FinancingLink
    {
        $link = FinancingLink::with('request')->where('company_id', $companyId)->findOrFail($linkId);
        abort_if(! $link->request || $link->request->isTradeIn(), 404);

        return $link;
    }

    /** Um documento de financiamento, ou o DUA de uma retoma (dados pessoais); as fotografias da retoma: 404 aqui. */
    private function financingDocument(int $companyId, int $documentId): FinancingDocument
    {
        $doc = FinancingDocument::with(['storedFile', 'link.request'])->where('company_id', $companyId)->findOrFail($documentId);
        $request = $doc->link?->request;
        abort_if($request && $request->isTradeIn() && ! in_array($doc->kind, FinancingDocumentTypes::PERSONAL, true), 404);

        return $doc;
    }

    private function settingsPayload(Request $request, int $companyId): array
    {
        $user = $request->user();
        $can = fn (string $p) => $this->access->can($user, $companyId, $p)->allowed;
        $company = Company::find($companyId);
        $year = (int) now('Europe/Lisbon')->format('Y');

        return [
            'can' => ['create' => $can('financiamento.criar'), 'delete' => $can('financiamento.apagar'), 'settings' => $can('empresa.editar')],
            'items' => collect(FinancingDocumentTypes::offered(FinancingRequest::FINANCING))->map(fn ($k) => [
                'key' => $k, 'label' => FinancingDocumentTypes::label($k), 'hint' => FinancingDocumentTypes::hint($k),
            ])->values(),
            'default_items' => FinancingDocumentTypes::defaultsFor($company),
            'default_items_own' => ! empty($company?->financing_default_items),
            'relationships' => collect(FinancingLink::RELATIONSHIPS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'defaults' => ['days' => (int) config('financing.link_days_default'), 'max_days' => (int) config('financing.link_days_max'),
                'irs_year' => FinancingDocumentTypes::defaultIrsYear(), 'irs_years' => range($year, $year - 6)],
            'retention' => ['days' => RetentionDays::for($company), 'min' => (int) config('financing.retention_days_min'),
                'max' => (int) config('financing.retention_days_max'), 'warn_days' => (int) config('financing.warn_days_before')],
            'antivirus_blocks' => $this->links->antivirusBlocks(),
        ];
    }
}
