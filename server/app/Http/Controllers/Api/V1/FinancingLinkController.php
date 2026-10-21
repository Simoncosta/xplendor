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
use App\Services\Financing\FinancingAudit;
use App\Services\Financing\FinancingDocumentTypes;
use App\Services\Financing\FinancingLinkService;
use App\Services\Financing\RetentionDays;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Envio seguro de documentos para financiamento, no painel (área "financiamento"; as permissões
 * de cada rota estão em RoutePermissions). Os pedidos e os documentos só da empresa da rota
 * (de outra empresa: 404). Os acessos do root a outra empresa e cada descarga ficam registados.
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
        $car = Car::where('company_id', $companyId)->findOrFail($carId);

        return $this->store($request, $car, null);
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
        $car = Car::where('company_id', $companyId)->findOrFail((int) $lead->car_id);

        return $this->store($request, $car, $lead);
    }

    // GET /companies/{id}/financing-links/{link}/url   (voltar a copiar o link)
    public function url(int $companyId, int $linkId): JsonResponse
    {
        $link = FinancingLink::where('company_id', $companyId)->findOrFail($linkId);
        abort_unless($link->isOpen(), 422, 'Este link já não está ativo.');

        return ApiResponse::success(['url' => $link->url()], 'Link.');
    }

    // POST /companies/{id}/financing-links/{link}/revoke
    public function revoke(Request $request, int $companyId, int $linkId): JsonResponse
    {
        $link = FinancingLink::where('company_id', $companyId)->findOrFail($linkId);
        $this->links->revoke($request->user(), $link);

        return ApiResponse::success($this->links->present($link->fresh(['documents', 'creator:id,name', 'company'])), 'Link revogado: o cliente já não o pode usar.');
    }

    // DELETE /companies/{id}/financing-links/{link}
    public function destroy(int $companyId, int $linkId): JsonResponse
    {
        $this->links->delete(FinancingLink::where('company_id', $companyId)->findOrFail($linkId));

        return ApiResponse::success(null, 'Pedido e documentos apagados.');
    }

    // GET /companies/{id}/financing-documents/{document}/download
    public function download(Request $request, int $companyId, int $documentId): JsonResponse
    {
        $doc = FinancingDocument::with('storedFile')->where('company_id', $companyId)->findOrFail($documentId);

        return ApiResponse::success(['url' => $this->links->downloadUrl($request->user(), $doc)], 'Endereço de descarga.');
    }

    // DELETE /companies/{id}/financing-documents/{document}
    public function destroyDocument(int $companyId, int $documentId): JsonResponse
    {
        $this->links->deleteDocument(FinancingDocument::where('company_id', $companyId)->findOrFail($documentId));

        return ApiResponse::success(null, 'Documento apagado.');
    }

    // GET /companies/{id}/financing-settings
    public function settings(Request $request, int $companyId): JsonResponse
    {
        return ApiResponse::success($this->settingsPayload($request, $companyId), 'Definições.');
    }

    // PUT /companies/{id}/financing-settings   { retention_days }
    public function updateSettings(Request $request, int $companyId): JsonResponse
    {
        $data = $request->validate(['retention_days' => ['required', 'integer', 'min:' . config('financing.retention_days_min'), 'max:' . config('financing.retention_days_max')]]);
        Company::whereKey($companyId)->update(['financing_retention_days' => (int) $data['retention_days']]);

        return ApiResponse::success($this->settingsPayload($request, $companyId), 'Prazo de apagamento guardado.');
    }

    private function store(Request $request, Car $car, ?CarLead $lead): JsonResponse
    {
        $items = array_keys(FinancingDocumentTypes::ITEMS);
        $data = $request->validate([
            'customer_name' => ['required', 'string', 'min:2', 'max:120'],
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['string', Rule::in($items)],
            'other' => [Rule::requiredIf(in_array('outro', (array) $request->input('items', []), true)), 'nullable', 'string', 'max:120'],
            'days' => ['nullable', 'integer', 'min:1', 'max:' . config('financing.link_days_max')],
        ], [
            'items.required' => 'Escolha pelo menos um documento.',
            'other.required' => 'Descreva o outro documento.',
        ]);
        $link = $this->links->create($request->user(), $car, $lead, $data);

        return ApiResponse::success($this->links->present($link->fresh(['documents', 'creator:id,name', 'company'])) + ['url' => $link->url()], 'Link criado.', 201);
    }

    private function listing(Request $request, int $companyId, $links): JsonResponse
    {
        return ApiResponse::success([
            'links' => $links->map(fn (FinancingLink $l) => $this->links->present($l))->values(),
        ] + $this->settingsPayload($request, $companyId), 'Pedidos de documentos.');
    }

    private function settingsPayload(Request $request, int $companyId): array
    {
        $user = $request->user();
        $can = fn (string $p) => $this->access->can($user, $companyId, $p)->allowed;

        return [
            'can' => ['create' => $can('financiamento.criar'), 'delete' => $can('financiamento.apagar'), 'settings' => $can('empresa.editar')],
            'items' => collect(FinancingDocumentTypes::ITEMS)->map(fn ($v, $k) => ['key' => $k, 'label' => $v[0], 'hint' => $v[1]])->values(),
            'defaults' => ['days' => (int) config('financing.link_days_default'), 'max_days' => (int) config('financing.link_days_max')],
            'retention' => ['days' => RetentionDays::for(Company::find($companyId)), 'min' => (int) config('financing.retention_days_min'),
                'max' => (int) config('financing.retention_days_max'), 'warn_days' => (int) config('financing.warn_days_before')],
            'antivirus_blocks' => $this->links->antivirusBlocks(),
        ];
    }
}
