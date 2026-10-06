<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\Quotes\QuotePublicService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Página pública do orçamento (sem login): a versão do link, o PDF dessa versão, o
 * sinal de abertura e as respostas do cliente (aceitar, recusar, pedir alterações).
 *
 * O token nunca vai no caminho: o link é /orcamento#<token> (o fragmento não chega ao
 * servidor nem aos registos de acesso) e a página envia-o no cabeçalho X-Quote-Token.
 * Todas as respostas levam X-Robots-Tag: noindex e Referrer-Policy: no-referrer.
 * Limites de pedidos nas rotas.
 */
class QuotePublicController extends Controller
{
    public function __construct(private readonly QuotePublicService $public) {}

    // GET /api/public/quote
    public function show(Request $request): JsonResponse
    {
        $link = $this->link($request);

        return $this->noindex(ApiResponse::success($this->public->payload($link), 'Orçamento.'));
    }

    // GET /api/public/quote/pdf
    public function pdf(Request $request): Response
    {
        $link = $this->link($request);
        $name = ($link->version->number ?: 'orcamento') . '-v' . $link->version->version . '.pdf';

        return $this->noindex(response($this->public->pdf($link), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $name . '"',
            'Cache-Control' => 'private, no-store',
        ]));
    }

    // POST /api/public/quote/open   { visitor_id, team_marker? }
    public function open(Request $request): JsonResponse
    {
        $link = $this->link($request);

        return $this->noindex(ApiResponse::success($this->public->recordOpen($link, $request), 'Registado.'));
    }

    // POST /api/public/quote/accept   { name, email, terms_accepted, optional_keys[] }
    public function accept(Request $request): JsonResponse
    {
        $link = $this->link($request);

        return $this->noindex(ApiResponse::success($this->public->accept($link, $request), 'Orçamento aceite. Obrigado.'));
    }

    // POST /api/public/quote/refuse   { reason? }
    public function refuse(Request $request): JsonResponse
    {
        $link = $this->link($request);

        return $this->noindex(ApiResponse::success($this->public->refuse($link, $request), 'Resposta registada.'));
    }

    // POST /api/public/quote/request-changes   { message }
    public function requestChanges(Request $request): JsonResponse
    {
        $link = $this->link($request);

        return $this->noindex(ApiResponse::success($this->public->requestChanges($link, $request), 'Pedido enviado à equipa.'));
    }

    /** O token vem do cabeçalho; mal formado ou desconhecido dá 404, sem distinguir. */
    private function link(Request $request): \App\Models\QuotePublicLink
    {
        $token = (string) $request->header('X-Quote-Token', '');
        if (! preg_match('/^[A-Za-z0-9]{64}$/', $token)) {
            abort(404, 'Orçamento não encontrado.');
        }

        return $this->public->resolve($token);
    }

    private function noindex(Response $response): Response
    {
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
