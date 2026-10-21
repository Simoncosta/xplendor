<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\FinancingLink;
use App\Services\Financing\FinancingPublicService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Página de envio de documentos para financiamento (sem conta). O código nunca vai no caminho:
 * o link é /enviar-documentos#<código> e a página envia-o no cabeçalho X-Financing-Token. Só três
 * ações: ver a lista pedida, aceitar o aviso e enviar um ficheiro. Não há rota para listar nem
 * para descarregar ficheiros. Todas as respostas: noindex, no-referrer e sem cache.
 */
class FinancingPublicController extends Controller
{
    public function __construct(private readonly FinancingPublicService $public) {}

    // GET /api/public/financing
    public function show(Request $request): JsonResponse
    {
        return $this->noindex(ApiResponse::success($this->public->payload($this->link($request)), 'Documentos pedidos.'));
    }

    // POST /api/public/financing/consent   { privacy: true, cc_copy: true }
    public function consent(Request $request): JsonResponse
    {
        $link = $this->link($request);
        $this->public->consent($link, $request->boolean('privacy'), $request->boolean('cc_copy'));

        return $this->noindex(ApiResponse::success($this->public->payload($link->fresh(['company', 'documents'])), 'Obrigado. Já pode enviar os documentos.'));
    }

    // POST /api/public/financing/documents   multipart: kind, file
    public function upload(Request $request): JsonResponse
    {
        $link = $this->link($request);
        $request->validate([
            'kind' => ['required', 'string', 'max:30'],
            'file' => ['required', 'file', 'max:' . intdiv((int) config('financing.max_file_bytes'), 1024)],
        ], ['file.max' => 'Cada ficheiro pode ter até ' . intdiv((int) config('financing.max_file_bytes'), 1048576) . ' MB.', 'file.required' => 'Escolha um ficheiro.']);
        $this->public->upload($link, (string) $request->input('kind'), $request->file('file'));

        return $this->noindex(ApiResponse::success($this->public->payload($link->fresh(['company', 'documents'])), 'Documento recebido.', 201));
    }

    /** O código vem do cabeçalho; mal formado, desconhecido, expirado ou revogado dá o mesmo 404. */
    private function link(Request $request): FinancingLink
    {
        $token = (string) $request->header('X-Financing-Token', '');
        if (! preg_match('/^[A-Za-z0-9]{' . FinancingLink::TOKEN_LENGTH . '}$/', $token)) {
            throw new HttpException(404, FinancingPublicService::GONE);
        }

        return $this->public->resolve($token);
    }

    private function noindex(Response $response): JsonResponse
    {
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
