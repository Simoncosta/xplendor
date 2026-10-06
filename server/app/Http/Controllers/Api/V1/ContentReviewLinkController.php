<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\ContentReviewLink;
use App\Services\ContentReview\ContentReviewPresenter;
use App\Services\ContentReview\ContentReviewService;
use App\Services\Editorial\EditorialWorkflowService;
use Illuminate\Http\Request;

/**
 * Links de aprovação por lote (F3c), do lado da app: listar, publicações candidatas,
 * criar e enviar, reenviar no mesmo link, prolongar, revogar e "Ver como o cliente"
 * (sem registar aberturas). O middleware tenant garante a empresa da rota; os links são
 * sempre procurados dentro dela.
 */
class ContentReviewLinkController extends Controller
{
    public function __construct(
        private readonly ContentReviewService $links,
        private readonly ContentReviewPresenter $presenter,
    ) {}

    public function index(Request $request, int $companyId)
    {
        $canProduce = EditorialWorkflowService::isProducer($request->user(), $companyId);

        return ApiResponse::success(['links' => $this->links->list($companyId, $canProduce), 'can_produce' => $canProduce], 'Links de aprovação.');
    }

    public function candidates(Request $request, int $companyId)
    {
        EditorialWorkflowService::assertProducer($request->user(), $companyId);

        return ApiResponse::success($this->links->candidates($companyId), 'Publicações em Aprovação.');
    }

    public function store(Request $request, int $companyId)
    {
        $link = $this->links->create(Company::findOrFail($companyId), $request->user(), $request->all());

        return ApiResponse::success($this->links->present($link), $link->recipient_email ? 'Link criado e enviado por email.' : 'Link criado.', 201);
    }

    // Reenviar no mesmo link (itens atualizados, destinatário, email de novo).
    public function update(Request $request, int $companyId, int $linkId)
    {
        $link = $this->links->resend($this->find($companyId, $linkId), $request->user(), $request->all());

        return ApiResponse::success($this->links->present($link), $link->recipient_email ? 'Link atualizado e reenviado por email.' : 'Link atualizado.');
    }

    public function extend(Request $request, int $companyId, int $linkId)
    {
        return ApiResponse::success($this->links->present($this->links->extend($this->find($companyId, $linkId), $request->user())), 'Validade prolongada por 14 dias.');
    }

    public function revoke(Request $request, int $companyId, int $linkId)
    {
        return ApiResponse::success($this->links->present($this->links->revoke($this->find($companyId, $linkId), $request->user())), 'Link revogado.');
    }

    // "Ver como o cliente": a mesma página, sem ações e sem contar como abertura.
    public function preview(Request $request, int $companyId, int $linkId)
    {
        return ApiResponse::success($this->presenter->payload($this->find($companyId, $linkId), preview: true), 'Pré-visualização.');
    }

    private function find(int $companyId, int $linkId): ContentReviewLink
    {
        $link = ContentReviewLink::where('company_id', $companyId)->find($linkId);
        abort_if(! $link, 404, 'Link não encontrado.');

        return $link;
    }
}
