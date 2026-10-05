<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\AiRequest;
use App\Services\Ai\AiRequestLifecycle;
use App\Models\Company;
use App\Services\Blog\BlogAiService;
use App\Services\BlogWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Blog — rascunhos com IA. Quem pode escrever artigos pode pedir (empresa, root e
 * impersonation). O middleware tenant garante a empresa da rota; os pedidos são sempre
 * procurados dentro dela.
 */
class BlogAiController extends Controller
{
    public function __construct(private readonly BlogAiService $ai) {}

    public function context(Request $request, int $companyId)
    {
        if (! BlogWorkflowService::canWrite($request->user(), $companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        return ApiResponse::success($this->ai->context(Company::with('contentSector')->findOrFail($companyId)), 'Contexto carregado.');
    }

    public function store(Request $request, int $companyId)
    {
        if (! BlogWorkflowService::canWrite($request->user(), $companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $data = $request->validate([
            'mode'                 => ['required', Rule::in([AiRequest::VARIANT_TOPIC, AiRequest::VARIANT_FROM_POST])],
            'topic'                => ['required_if:mode,topic', 'nullable', 'string', 'max:300'],
            'source_text'          => ['required_if:mode,from_post', 'nullable', 'string', 'max:5000'],
            'keyword'              => ['nullable', 'string', 'max:100'],
            'secondary_keywords'   => ['nullable', 'array', 'max:10'],
            'secondary_keywords.*' => ['string', 'max:60'],
            'notes'                => ['nullable', 'string', 'max:1000'],
            'blog_id'              => ['nullable', 'integer'],
        ], [
            'topic.required_if'       => 'Indique o tema do artigo.',
            'source_text.required_if' => 'Cole o texto da publicação.',
        ]);

        $input = array_intersect_key($data, array_flip(['topic', 'source_text', 'keyword', 'secondary_keywords', 'notes']));
        $draft = $this->ai->request(Company::findOrFail($companyId), $request->user(), $data['mode'], $input, $data['blog_id'] ?? null);

        return ApiResponse::success($this->present($draft), 'Pedido enviado.', 202);
    }

    public function show(Request $request, int $companyId, int $draftId)
    {
        $draft = AiRequest::where('company_id', $companyId)->where('mode', AiRequest::MODE_BLOG)->find($draftId);
        if (! $draft) {
            return ApiResponse::error('Pedido não encontrado.', 404);
        }

        return ApiResponse::success($this->present($draft), 'Pedido carregado.');
    }

    private function present(AiRequest $d): array
    {
        return app(AiRequestLifecycle::class)->present($d);
    }
}
