<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\AiRequest;
use App\Models\Company;
use App\Models\EditorialPost;
use App\Services\Ai\AiRequestLifecycle;
use App\Services\Editorial\CaptionAiService;
use App\Services\Editorial\EditorialWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * "Gerar legenda" (IA): pedir propostas e consultar o estado. Não há gravação aqui: a proposta
 * escolhida passa para o rascunho da versão no ecrã e a pessoa grava-a pelo caminho normal.
 * Quem pode: os mesmos que produzem as publicações da empresa.
 */
class EditorialCaptionController extends Controller
{
    public function __construct(private readonly CaptionAiService $ai) {}

    public function suggest(Request $request, int $companyId, int $postId)
    {
        EditorialWorkflowService::assertProducer($request->user(), $companyId);
        $data = $request->validate([
            'networks'   => ['sometimes', 'array'],
            'networks.*' => ['string', Rule::in(EditorialPost::NETWORKS)],
        ]);
        $r = $this->ai->request(Company::with('contentSector')->findOrFail($companyId), $request->user(), $postId, $data['networks'] ?? null);

        return ApiResponse::success(app(AiRequestLifecycle::class)->present($r), 'Pedido enviado.', 202);
    }

    public function suggestion(int $companyId, int $postId, int $suggestionId)
    {
        $r = AiRequest::where('company_id', $companyId)->where('mode', AiRequest::MODE_CAPTION)
            ->where('editorial_post_id', $postId)->find($suggestionId);
        if (! $r) {
            return ApiResponse::error('Pedido não encontrado.', 404);
        }

        return ApiResponse::success(app(AiRequestLifecycle::class)->present($r), 'Pedido carregado.');
    }
}
