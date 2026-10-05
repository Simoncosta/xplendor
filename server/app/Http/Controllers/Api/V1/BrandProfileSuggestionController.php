<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\AiRequest;
use App\Models\Company;
use App\Services\Ai\AiRequestQuota;
use App\Services\Brand\BrandProfileAiService;
use App\Services\CollaboratorService;
use Illuminate\Http\Request;

/**
 * "Sugerir perfil": pede uma proposta à IA e consulta o estado. A proposta NUNCA é gravada
 * no perfil: o ecrã mostra-a campo a campo e o humano decide (e depois guarda o perfil).
 * Só quem pode alterar o perfil (administrador e equipa XPLENDOR) pode pedir sugestões.
 */
class BrandProfileSuggestionController extends Controller
{
    public function __construct(private readonly BrandProfileAiService $ai) {}

    public function store(Request $request, int $companyId)
    {
        if (! CollaboratorService::canEditContent($request->user(), $companyId)) {
            return ApiResponse::error('Só o administrador da empresa pode pedir sugestões para o perfil da marca.', 403);
        }

        $suggestion = $this->ai->request(Company::with('contentSector')->findOrFail($companyId), $request->user());

        return ApiResponse::success($this->present($suggestion), 'Pedido enviado.', 202);
    }

    public function show(Request $request, int $companyId, int $suggestionId)
    {
        $suggestion = AiRequest::where('company_id', $companyId)->where('mode', AiRequest::MODE_BRAND_PROFILE)->find($suggestionId);
        if (! $suggestion) {
            return ApiResponse::error('Pedido não encontrado.', 404);
        }

        return ApiResponse::success($this->present($suggestion), 'Pedido carregado.');
    }

    private function present(AiRequest $r): array
    {
        return [
            'id'            => $r->id,
            'status'        => $r->status,
            'result'        => $r->status === AiRequest::DONE ? $r->result : null,
            'template'      => $r->input['template'] ?? null,
            'audience_warning' => $r->context['audience']['warning'] ?? null,
            'error_message' => $r->error_message,
            'used'          => AiRequestQuota::used((int) $r->company_id, AiRequest::MODE_BRAND_PROFILE),
            'cap'           => AiRequestQuota::cap(AiRequest::MODE_BRAND_PROFILE),
        ];
    }
}
