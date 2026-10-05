<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\AiRequest;
use App\Models\Company;
use App\Models\EditorialPost;
use App\Models\EditorialPostCreative;
use App\Services\Ai\AiRequestQuota;
use App\Services\Ai\AiText;
use App\Services\Brand\CreativeAiService;
use App\Services\EditorialLineService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Criativos da Linha Editorial: pedir uma sugestão à IA, consultar o estado, ver o criativo
 * aceite e ACEITAR campo a campo (só os campos enviados são gravados). A sugestão nunca é
 * gravada sozinha. Quem pode: os mesmos que editam as publicações da empresa.
 */
class EditorialCreativeController extends Controller
{
    private const FIELDS = ['media_format', 'hook', 'caption', 'hashtags', 'cta'];

    public function __construct(
        private readonly CreativeAiService $ai,
        private readonly EditorialLineService $line,
    ) {}

    public function suggest(Request $request, int $companyId, int $postId)
    {
        $suggestion = $this->ai->request(Company::with('contentSector')->findOrFail($companyId), $request->user(), $postId);

        return ApiResponse::success($this->presentSuggestion($suggestion), 'Pedido enviado.', 202);
    }

    public function suggestion(int $companyId, int $postId, int $suggestionId)
    {
        $suggestion = AiRequest::where('company_id', $companyId)
            ->where('mode', AiRequest::MODE_CREATIVE)
            ->where('editorial_post_id', $postId)
            ->find($suggestionId);
        if (! $suggestion) {
            return ApiResponse::error('Pedido não encontrado.', 404);
        }

        return ApiResponse::success($this->presentSuggestion($suggestion), 'Pedido carregado.');
    }

    public function show(int $companyId, int $postId)
    {
        $post = $this->findPost($companyId, $postId);
        if (! $post) {
            return ApiResponse::error('Publicação não encontrada.', 404);
        }

        return ApiResponse::success($this->presentCreative($post), 'Criativo carregado.');
    }

    /** Aceita os campos escolhidos (só os enviados). O formato aceite passa também para a publicação. */
    public function accept(Request $request, int $companyId, int $postId)
    {
        $post = $this->findPost($companyId, $postId);
        if (! $post) {
            return ApiResponse::error('Publicação não encontrada.', 404);
        }
        if (! in_array($post->channel, CreativeAiService::CHANNELS, true)) {
            return ApiResponse::error('Os criativos são para publicações do Instagram e do Facebook.', 422);
        }

        $data = $request->validate([
            'suggestion_id' => ['nullable', 'integer'],
            'media_format'  => ['sometimes', 'nullable', Rule::in(EditorialPost::MEDIA_FORMATS[$post->channel])],
            'hook'          => ['sometimes', 'nullable', 'string', 'max:200'],
            'caption'       => ['sometimes', 'nullable', 'string', 'max:2200'],
            'hashtags'      => ['sometimes', 'nullable', 'array', 'max:30'],
            'hashtags.*'    => ['nullable', 'string', 'max:60'],
            'cta'           => ['sometimes', 'nullable', 'string', 'max:300'],
        ], [
            'media_format.in' => 'Escolha um formato válido para esta rede.',
        ]);

        $accepted = array_intersect_key($data, array_flip(self::FIELDS));
        if ($accepted === []) {
            return ApiResponse::error('Escolha pelo menos um campo para guardar.', 422);
        }

        $suggestion = null;
        if (! empty($data['suggestion_id'])) {
            $suggestion = AiRequest::where('company_id', $companyId)->where('mode', AiRequest::MODE_CREATIVE)
                ->where('editorial_post_id', $post->id)->where('status', AiRequest::DONE)->find($data['suggestion_id']);
            if (! $suggestion) {
                return ApiResponse::error('Sugestão não encontrada para esta publicação.', 404);
            }
        }

        try {
            $this->line->assertDateEditable(Company::findOrFail($companyId), $post->publish_date->toDateString());
        } catch (ValidationException $e) {
            return ApiResponse::error($e->validator->errors()->first(), 422);
        }

        if (array_key_exists('hook', $accepted)) {
            $accepted['hook'] = AiText::plain($accepted['hook'] ?? '', 200) ?: null;
        }
        if (array_key_exists('caption', $accepted)) {
            $accepted['caption'] = AiText::plain($accepted['caption'] ?? '', 2200, true) ?: null;
        }
        if (array_key_exists('cta', $accepted)) {
            $accepted['cta'] = AiText::plain($accepted['cta'] ?? '', 300) ?: null;
        }
        if (array_key_exists('hashtags', $accepted)) {
            $accepted['hashtags'] = AiText::hashtags($accepted['hashtags'] ?? []);
        }

        DB::transaction(function () use ($post, $accepted, $suggestion, $request) {
            $creative = EditorialPostCreative::firstOrNew(['editorial_post_id' => $post->id]);
            $creative->fill($accepted + [
                'company_id'          => $post->company_id,
                'accepted_by_user_id' => $request->user()->id,
                'accepted_at'         => now(),
            ]);
            if ($suggestion) {
                $creative->fill([
                    'ai_request_id' => $suggestion->id,
                    'rationale'     => $suggestion->result['why'] ?? null,
                    'source'        => $suggestion->result['source'] ?? null,
                    'source_label'  => $suggestion->result['source_label'] ?? null,
                ]);
            }
            $creative->save();

            if (array_key_exists('media_format', $accepted)) {
                $post->update(['media_format' => $accepted['media_format']]);
            }
        });

        return ApiResponse::success($this->presentCreative($post->fresh()), 'Criativo guardado.');
    }

    // ── apresentação ──────────────────────────────────────────────────────────

    private function findPost(int $companyId, int $postId): ?EditorialPost
    {
        return EditorialPost::where('company_id', $companyId)->find($postId);
    }

    private function presentSuggestion(AiRequest $r): array
    {
        return [
            'id'            => $r->id,
            'post_id'       => $r->editorial_post_id,
            'status'        => $r->status,
            'result'        => $r->status === AiRequest::DONE ? $r->result : null,
            'error_message' => $r->error_message,
            'used'          => AiRequestQuota::used((int) $r->company_id, AiRequest::MODE_CREATIVE),
            'cap'           => AiRequestQuota::cap(AiRequest::MODE_CREATIVE),
        ];
    }

    private function presentCreative(EditorialPost $post): array
    {
        $c = EditorialPostCreative::where('editorial_post_id', $post->id)->first();

        return [
            'post_id'      => $post->id,
            'channel'      => $post->channel,
            'media_format' => $post->media_format,
            'formats'      => array_map(fn ($k) => ['value' => $k, 'label' => EditorialPost::MEDIA_FORMAT_LABELS[$k]], EditorialPost::MEDIA_FORMATS[$post->channel] ?? []),
            'creative'     => $c ? [
                'media_format' => $c->media_format,
                'hook'         => $c->hook,
                'caption'      => $c->caption,
                'hashtags'     => $c->hashtags ?? [],
                'cta'          => $c->cta,
                'rationale'    => $c->rationale,
                'source'       => $c->source,
                'source_label' => $c->source_label,
                'accepted_at'  => optional($c->accepted_at)->toIso8601String(),
            ] : null,
        ];
    }
}
