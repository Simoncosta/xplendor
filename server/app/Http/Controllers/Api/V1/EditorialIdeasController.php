<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\AiRequest;
use App\Models\Company;
use App\Models\EditorialPost;
use App\Services\Ai\AiRequestLifecycle;
use App\Services\Editorial\EditorialIdeasAiService;
use App\Services\EditorialLineService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * "Gerar ideias do mês" (Linha Editorial): pedir à IA, consultar o estado e aceitar ideia a
 * ideia (cada uma vira uma publicação em rascunho). O middleware tenant garante a empresa
 * da rota; os pedidos são sempre procurados dentro dela. Quem pode: os mesmos que criam
 * publicações (utilizadores da empresa e a equipa XPLENDOR).
 */
class EditorialIdeasController extends Controller
{
    public function __construct(
        private readonly EditorialIdeasAiService $ideas,
        private readonly EditorialLineService $line,
    ) {}

    public function store(Request $request, int $companyId)
    {
        $data = $request->validate([
            'year'  => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $req = $this->ideas->request(Company::with('contentSector')->findOrFail($companyId), $request->user(), (int) $data['year'], (int) $data['month']);

        return ApiResponse::success($this->present($req), 'Pedido enviado.', 202);
    }

    public function show(int $companyId, int $requestId)
    {
        $req = AiRequest::where('company_id', $companyId)->where('mode', AiRequest::MODE_IDEAS)->find($requestId);
        if (! $req) {
            return ApiResponse::error('Pedido não encontrado.', 404);
        }

        return ApiResponse::success($this->present($req), 'Pedido carregado.');
    }

    /** Aceita UMA ideia (data e canal opcionais). Devolve o pedido atualizado e o calendário. */
    public function accept(Request $request, int $companyId, int $requestId)
    {
        $data = $request->validate([
            'index'        => ['required', 'integer', 'min:0', 'max:' . (EditorialIdeasAiService::MAX_IDEAS - 1)],
            'publish_date' => ['nullable', 'date'],
            'channel'      => ['nullable', Rule::in(EditorialPost::CHANNELS)],
        ]);

        $company = Company::with('contentSector')->findOrFail($companyId);
        try {
            $out = $this->ideas->accept($company, $requestId, (int) $data['index'], $data['publish_date'] ?? null, $data['channel'] ?? null);
        } catch (ValidationException $e) {
            return ApiResponse::error($e->validator->errors()->first(), 422);
        }

        return ApiResponse::success([
            'ideas'    => $this->present($out['request']),
            'post_id'  => $out['post']->id,
            'calendar' => $this->line->calendar($company),
        ], 'Ideia aceite: publicação criada em rascunho.');
    }

    private function present(AiRequest $r): array
    {
        return app(AiRequestLifecycle::class)->present($r);
    }
}
