<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\EditorialPost;
use App\Services\Editorial\EditorialPublishingService;
use App\Services\Editorial\EditorialWorkflowService;
use Illuminate\Http\Request;

/**
 * F3d: "Para publicar hoje" e atrasadas, marcar como publicada (link e hora real),
 * resultados à mão e resultados do mês. O middleware tenant garante a empresa da rota;
 * as publicações são sempre procuradas dentro dela.
 */
class EditorialPublishingController extends Controller
{
    public function __construct(
        private readonly EditorialPublishingService $publishing,
        private readonly EditorialWorkflowService $workflow,
    ) {}

    public function today(Request $request, int $companyId)
    {
        return ApiResponse::success($this->publishing->today($companyId, $request->user()), 'Para publicar hoje.');
    }

    // POST { network, url, published_at }
    public function markPublished(Request $request, int $companyId, int $postId)
    {
        $post = $this->publishing->markPublished($this->post($companyId, $postId), $request->user(), $request->only(['network', 'url', 'published_at']));

        return ApiResponse::success($this->workflow->detail($post, $request->user()), 'Marcada como publicada.');
    }

    // POST { network, reason }  "Não publicar nesta rede"
    public function skipNetwork(Request $request, int $companyId, int $postId)
    {
        $post = $this->publishing->skipNetwork($this->post($companyId, $postId), $request->user(), $request->only(['network', 'reason']));

        return ApiResponse::success($this->workflow->detail($post, $request->user()), 'Registado: não publicar nesta rede.');
    }

    // PUT { network, measured_on, reach, interactions, likes, comments, saves, shares, clicks, video_views, worked, change }
    public function saveResults(Request $request, int $companyId, int $postId)
    {
        $post = $this->publishing->saveMetrics($this->post($companyId, $postId), $request->user(), $request->all());

        return ApiResponse::success($this->workflow->detail($post, $request->user()), 'Resultados guardados.');
    }

    public function results(Request $request, int $companyId)
    {
        $data = $request->validate(['month' => ['required', 'regex:/^\d{4}-\d{2}$/']]);

        return ApiResponse::success(['month' => $data['month'], 'rows' => $this->publishing->results($companyId, $data['month'])], 'Resultados do mês.');
    }

    private function post(int $companyId, int $postId): EditorialPost
    {
        $post = EditorialPost::where('company_id', $companyId)->find($postId);
        abort_if(! $post, 404, 'Publicação não encontrada.');

        return $post;
    }
}
