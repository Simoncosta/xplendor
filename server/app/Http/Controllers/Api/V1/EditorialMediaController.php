<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\EditorialPost;
use App\Models\MediaAsset;
use App\Models\MediaUpload;
use App\Services\Editorial\EditorialWorkflowService;
use App\Services\Media\MediaService;
use Illuminate\Http\Request;

/**
 * Media da Linha Editorial (F3b): envio em partes de 8 MB (retomável), estado do
 * processamento, media da versão em edição e a grelha do Instagram. Enviar e mudar os
 * media é produção (o cliente gerido pela equipa não o faz). Tudo dentro da empresa da rota.
 */
class EditorialMediaController extends Controller
{
    public function __construct(
        private readonly MediaService $media,
        private readonly EditorialWorkflowService $workflow,
    ) {}

    public function startUpload(Request $request, int $companyId)
    {
        EditorialWorkflowService::assertProducer($request->user(), $companyId);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1'],
            'mime' => ['required', 'string', 'max:60'],
        ]);
        $upload = $this->media->start($companyId, $request->user(), $data['name'], (int) $data['size'], $data['mime']);

        return ApiResponse::success($this->presentUpload($upload), 'Envio iniciado.', 201);
    }

    public function uploadStatus(Request $request, int $companyId, string $uploadId)
    {
        return ApiResponse::success($this->presentUpload($this->upload($companyId, $uploadId)), 'Envio.');
    }

    /** Uma parte: offset tem de ser o received_bytes atual (409 devolve o valor para retomar). */
    public function chunk(Request $request, int $companyId, string $uploadId)
    {
        EditorialWorkflowService::assertProducer($request->user(), $companyId);
        $data = $request->validate([
            'offset' => ['required', 'integer', 'min:0'],
            'chunk' => ['required', 'file'],
        ]);
        $upload = $this->upload($companyId, $uploadId);
        try {
            $upload = $this->media->appendChunk($upload, (int) $data['offset'], $request->file('chunk'));
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            if ($e->getStatusCode() === 409) {
                return ApiResponse::error('A parte não continua o envio; retome do ponto indicado.', 409, ['received_bytes' => $upload->fresh()->received_bytes]);
            }
            throw $e;
        }

        return ApiResponse::success($this->presentUpload($upload), $upload->completed_at ? 'Ficheiro recebido.' : 'Parte recebida.');
    }

    public function asset(int $companyId, int $assetId)
    {
        $asset = MediaAsset::where('company_id', $companyId)->find($assetId);
        abort_if(! $asset, 404, 'Ficheiro não encontrado.');

        return ApiResponse::success($asset->present(), 'Ficheiro.');
    }

    /** Define os media da versão em edição: { items: [ids por ordem], cover_id? }. */
    public function setPostMedia(Request $request, int $companyId, int $postId)
    {
        $data = $request->validate([
            'items' => ['present', 'array', 'max:10'],
            'items.*' => ['integer'],
            'cover_id' => ['nullable', 'integer'],
        ]);
        $post = EditorialPost::where('company_id', $companyId)->find($postId);
        abort_if(! $post, 404, 'Publicação não encontrada.');
        $this->workflow->setMedia($post, $request->user(), $data['items'], $data['cover_id'] ?? null);

        return ApiResponse::success($this->workflow->detail($post->fresh(), $request->user()), 'Ficheiros guardados.');
    }

    /**
     * Feed (substitui a grelha): como o perfil vai ficar em cada rede.
     *  · Instagram: grelha do perfil, as próximas (da mais distante para a mais próxima) por
     *    cima das últimas publicadas; cada mosaico usa a capa ou o primeiro ficheiro.
     *  · Facebook: cronologia da Página, uma publicação por baixo da outra (a mais recente
     *    primeiro), com a legenda da rede e os ficheiros.
     */
    public function grid(int $companyId)
    {
        $today = now('Europe/Lisbon')->toDateString();
        $base = fn (string $network) => EditorialPost::where('company_id', $companyId)
            ->whereHas('networks', fn ($q) => $q->where('network', $network)->whereNull('skipped_at'))
            ->with(['currentVersion', 'networks']);
        $upcoming = fn (string $network, int $limit) => $base($network)->whereDate('publish_date', '>=', $today)
            ->whereNotIn('stage', [EditorialPost::STAGE_IDEA, EditorialPost::STAGE_PUBLISHED, EditorialPost::STAGE_ANALYSIS])
            ->orderByDesc('publish_date')->orderByDesc('id')->limit($limit)->get();
        $published = fn (string $network, int $limit) => $base($network)->whereIn('stage', [EditorialPost::STAGE_PUBLISHED, EditorialPost::STAGE_ANALYSIS])
            ->orderByDesc('publish_date')->orderByDesc('id')->limit($limit)->get();

        $tile = function (EditorialPost $p, string $network) {
            $media = EditorialWorkflowService::presentMedia($p->currentVersion);
            $first = $media['cover'] ?? ($media['items'][0] ?? null);
            $net = $p->networks->firstWhere('network', $network);

            return [
                'id' => $p->id, 'title' => $p->title, 'publish_date' => $p->publish_date->toDateString(), 'publish_time' => $p->publish_time,
                'stage' => $p->stage, 'media_format' => $net?->media_format, 'state' => $net?->state(),
                'items_count' => count($media['items']),
                'image_url' => $first['preview_url'] ?? null,
            ];
        };
        $story = fn (EditorialPost $p) => $tile($p, 'facebook') + [
            'caption' => $p->currentVersion?->captionFor('facebook') ?? '',
            'hashtags' => $p->currentVersion?->hashtags ?? [],
            'media' => EditorialWorkflowService::presentMedia($p->currentVersion),
        ];

        // Nome e foto reais das contas ligadas (ou o nome da empresa).
        $company = \App\Models\Company::find($companyId);
        $accounts = \App\Models\SocialConnectionAccount::where('company_id', $companyId)->orderByDesc('is_primary')->orderBy('id')->get();
        $account = function (string $platform) use ($accounts, $company) {
            $a = $accounts->firstWhere('platform', $platform);

            return [
                'name' => $a?->name ?: (string) ($company?->trade_name ?: $company?->fiscal_name),
                'username' => $a?->username,
                'avatar_url' => $a?->profile_picture_path ? \Illuminate\Support\Facades\URL::temporarySignedRoute('social.avatar', now()->addMinutes(30), ['account' => $a->id], absolute: false) : null,
            ];
        };

        return ApiResponse::success([
            'accounts' => ['instagram' => $account('instagram'), 'facebook' => $account('facebook')],
            'upcoming' => $upcoming('instagram', 30)->map(fn ($p) => $tile($p, 'instagram'))->all(),
            'published' => $published('instagram', 12)->map(fn ($p) => $tile($p, 'instagram'))->all(),
            'facebook' => [
                'upcoming' => $upcoming('facebook', 15)->map($story)->all(),
                'published' => $published('facebook', 10)->map($story)->all(),
            ],
        ], 'Feed.');
    }

    private function upload(int $companyId, string $uploadId): MediaUpload
    {
        $upload = MediaUpload::where('company_id', $companyId)->find($uploadId);
        abort_if(! $upload, 404, 'Envio não encontrado.');

        return $upload;
    }

    private function presentUpload(MediaUpload $u): array
    {
        $asset = $u->media_asset_id ? MediaAsset::find($u->media_asset_id) : null;

        return [
            'id' => $u->id, 'kind' => $u->kind, 'size_bytes' => $u->size_bytes, 'received_bytes' => $u->received_bytes,
            'chunk_bytes' => (int) config('media.chunk_bytes'), 'completed' => $u->completed_at !== null,
            'asset' => $asset?->present(),
        ];
    }
}
