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
 * Todas as respostas levam X-Robots-Tag: noindex. Limites de pedidos nas rotas.
 */
class QuotePublicController extends Controller
{
    public function __construct(private readonly QuotePublicService $public) {}

    // GET /api/public/quotes/{token}
    public function show(string $token): JsonResponse
    {
        $link = $this->public->resolve($token);

        return $this->noindex(ApiResponse::success($this->public->payload($link), 'Orçamento.'));
    }

    // GET /api/public/quotes/{token}/pdf
    public function pdf(string $token): Response
    {
        $link = $this->public->resolve($token);
        $name = ($link->version->number ?: 'orcamento') . '-v' . $link->version->version . '.pdf';

        return $this->noindex(response($this->public->pdf($link), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $name . '"',
            'Cache-Control' => 'private, no-store',
        ]));
    }

    // POST /api/public/quotes/{token}/open   { visitor_id, team_marker? }
    public function open(Request $request, string $token): JsonResponse
    {
        $link = $this->public->resolve($token);

        return $this->noindex(ApiResponse::success($this->public->recordOpen($link, $request), 'Registado.'));
    }

    // POST /api/public/quotes/{token}/accept   { name, email, terms_accepted, optional_keys[] }
    public function accept(Request $request, string $token): JsonResponse
    {
        $link = $this->public->resolve($token);

        return $this->noindex(ApiResponse::success($this->public->accept($link, $request), 'Orçamento aceite. Obrigado.'));
    }

    // POST /api/public/quotes/{token}/refuse   { reason? }
    public function refuse(Request $request, string $token): JsonResponse
    {
        $link = $this->public->resolve($token);

        return $this->noindex(ApiResponse::success($this->public->refuse($link, $request), 'Resposta registada.'));
    }

    // POST /api/public/quotes/{token}/request-changes   { message }
    public function requestChanges(Request $request, string $token): JsonResponse
    {
        $link = $this->public->resolve($token);

        return $this->noindex(ApiResponse::success($this->public->requestChanges($link, $request), 'Pedido enviado à equipa.'));
    }

    private function noindex(Response $response): Response
    {
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }
}
