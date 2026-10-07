<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\CompanySetupLink;
use App\Services\CollaboratorService;
use App\Services\Setup\SetupLinkService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Link de configuração do cliente, do lado da equipa: ver o link e o estado de cada passo,
 * gerar (revoga o anterior), renovar e revogar. Quem pode gerir: o admin da empresa, o root
 * e o admin da agência gestora (SetupLinkService). O histórico das ligações vê-o qualquer
 * utilizador da empresa.
 */
class CompanySetupLinkController extends Controller
{
    public function __construct(private readonly SetupLinkService $links) {}

    // GET /companies/{id}/setup-link
    public function show(Request $request, int $companyId)
    {
        $this->links->assertCanManage($request->user(), $companyId);

        return ApiResponse::success($this->payload($companyId), 'Link de configuração.');
    }

    // POST /companies/{id}/setup-links   { steps: [social|meta_ads|ga4] }
    public function store(Request $request, int $companyId)
    {
        $this->links->assertCanManage($request->user(), $companyId); // antes da validação: um membro recebe 403
        $data = $request->validate([
            'steps' => ['required', 'array', 'min:1', 'max:3'],
            'steps.*' => ['string', Rule::in(array_keys(CompanySetupLink::STEPS))],
            'support_ticket_id' => ['nullable', 'integer'],
        ], ['steps.required' => 'Escolha pelo menos um passo.', 'steps.min' => 'Escolha pelo menos um passo.', 'steps.*.in' => 'Passo desconhecido.']);
        $this->links->create($companyId, $request->user(), array_values(array_unique($data['steps'])), isset($data['support_ticket_id']) ? (int) $data['support_ticket_id'] : null);

        return ApiResponse::success($this->payload($companyId), 'Link de configuração gerado. O link anterior deixou de funcionar.', 201);
    }

    // POST /companies/{id}/setup-links/{linkId}/extend
    public function extend(Request $request, int $companyId, int $linkId)
    {
        $this->links->extend($companyId, $linkId, $request->user());

        return ApiResponse::success($this->payload($companyId), 'Link renovado por mais 14 dias.');
    }

    // POST /companies/{id}/setup-links/{linkId}/revoke
    public function revoke(Request $request, int $companyId, int $linkId)
    {
        $this->links->revoke($companyId, $linkId, $request->user());

        return ApiResponse::success($this->payload($companyId), 'Link revogado. Deixou de funcionar.');
    }

    // GET /companies/{id}/integrations/history
    public function history(Request $request, int $companyId)
    {
        return ApiResponse::success([
            'events' => $this->links->history($companyId),
            'can_manage' => CollaboratorService::canConfigureIntegrations($request->user(), $companyId),
        ], 'Histórico das ligações.');
    }

    private function payload(int $companyId): array
    {
        return [
            'company_name' => \App\Services\Agency\AgencyNotifier::name(\App\Models\Company::find($companyId)),
            'link' => $this->links->present($this->links->latest($companyId)),
            'steps' => collect(CompanySetupLink::STEPS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()->all(),
            'validity_days' => CompanySetupLink::VALIDITY_DAYS,
            // Enquanto a Meta não aprovar a app, o passo do Facebook só funciona para contas de teste.
            'meta_app_review_pending' => ! (bool) config('services.meta.app_approved', false),
        ];
    }
}
