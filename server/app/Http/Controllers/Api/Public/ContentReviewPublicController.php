<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\MediaFileController;
use App\Http\Controllers\Controller;
use App\Models\ContentReviewLink;
use App\Models\MediaAsset;
use App\Services\ContentReview\ContentReviewPresenter;
use App\Services\ContentReview\ContentReviewPublicService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Página do link de aprovação de conteúdos (sem conta), com o padrão do link do orçamento.
 *
 * O token nunca vai no caminho: o link é /aprovar#<token> (o fragmento não chega ao
 * servidor nem aos registos de acesso) e a página envia-o no cabeçalho X-Review-Token.
 * Todas as respostas levam X-Robots-Tag: noindex e Referrer-Policy: no-referrer. Limites
 * de pedidos nas rotas. Os ficheiros vêm por URLs assinados que só servem para este lote
 * e enquanto o link estiver válido.
 */
class ContentReviewPublicController extends Controller
{
    public function __construct(private readonly ContentReviewPublicService $public) {}

    // GET /api/public/review
    public function show(Request $request): JsonResponse
    {
        return $this->noindex(ApiResponse::success($this->public->payload($this->link($request)), 'Publicações para aprovar.'));
    }

    // POST /api/public/review/open   { visitor_id, team_marker? }
    public function open(Request $request): JsonResponse
    {
        return $this->noindex(ApiResponse::success($this->public->recordOpen($this->link($request), $request), 'Registado.'));
    }

    // POST /api/public/review/items/{item}/approve   { name }
    public function approve(Request $request, int $item): JsonResponse
    {
        return $this->noindex(ApiResponse::success($this->public->approve($this->link($request), $item, $request), 'Publicação aprovada. Obrigado.'));
    }

    // POST /api/public/review/items/{item}/request-changes   { name, message }
    public function requestChanges(Request $request, int $item): JsonResponse
    {
        return $this->noindex(ApiResponse::success($this->public->requestChanges($this->link($request), $item, $request), 'Pedido enviado à equipa.'));
    }

    // POST /api/public/review/items/{item}/comments   { name, body }
    public function comment(Request $request, int $item): JsonResponse
    {
        return $this->noindex(ApiResponse::success($this->public->comment($this->link($request), $item, $request), 'Comentário enviado.'));
    }

    // POST /api/public/review/approve-all   { name }
    public function approveAll(Request $request): JsonResponse
    {
        return $this->noindex(ApiResponse::success($this->public->approveAll($this->link($request), $request), 'Publicações aprovadas. Obrigado.'));
    }

    // GET /api/public/review/media/{link}/{asset}/{variant}   (URL assinado)
    public function media(int $link, int $asset, string $variant): Response
    {
        $review = ContentReviewLink::find($link);
        abort_if(! $review || ! $review->isOpen() || ! ContentReviewPresenter::assetInLink($review, $asset), 404);

        return MediaFileController::serve(MediaAsset::find($asset), $variant);
    }

    /** O token vem do cabeçalho; mal formado ou desconhecido dá 404, sem distinguir. */
    private function link(Request $request): ContentReviewLink
    {
        $token = (string) $request->header('X-Review-Token', '');
        if (! preg_match('/^[A-Za-z0-9]{64}$/', $token)) {
            abort(404, 'Link não encontrado.');
        }

        return $this->public->resolve($token);
    }

    private function noindex(Response $response): Response
    {
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
