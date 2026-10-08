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
use App\Services\Restaurant\RestaurantCompassService;
use App\Services\Restaurant\RestaurantSignalPanelService;
use App\Services\Editorial\NetworkFormats;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Bússola (marketing da restauração):
 *  · GET  .../marketing/bussola?location_id=&summary=    a página (ou só o topo e as jogadas)
 *  · POST .../marketing/bussola/post                     "Criar publicação" a partir de uma jogada
 *  · POST .../marketing/bussola/caption                  "Sugerir texto" (sem criar publicação)
 *  · GET  .../marketing/bussola/caption/{requestId}      estado das propostas
 * Ver: qualquer utilizador da empresa. Criar e sugerir: quem produz na Linha Editorial.
 */
class RestaurantCompassController extends Controller
{
    public function __construct(
        private readonly RestaurantCompassService $compass,
        private readonly RestaurantSignalPanelService $panel,
    ) {}

    public function show(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $data = $request->validate(['location_id' => ['nullable', 'integer'], 'summary' => ['nullable', 'boolean'], 'plays_only' => ['nullable', 'boolean']]);
        [$can, $reason] = $this->panel->canAct($request->user(), $companyId);

        return ApiResponse::success($this->compass->payload($companyId, isset($data['location_id']) ? (int) $data['location_id'] : null, (bool) ($data['summary'] ?? false), (bool) ($data['plays_only'] ?? false))
            + ['can_act' => $can, 'can_act_reason' => $reason, 'formats' => EditorialPost::FORMATS], 'Bússola.');
    }

    public function createPost(Request $request, int $companyId)
    {
        $this->assertCanAct($request, $companyId);
        $today = CarbonImmutable::now('Europe/Lisbon')->toDateString();
        $data = $request->validate([
            'play_key' => ['required', 'string', 'max:160'],
            'location_id' => ['nullable', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'publish_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:' . $today],
            'networks' => ['required', 'array', 'min:1', 'max:2'],
            'networks.*' => ['string', Rule::in(EditorialPost::NETWORKS)],
            'media_formats' => ['nullable', 'array'],
            'media_formats.*' => ['nullable', 'string', Rule::in(array_merge(...array_values(NetworkFormats::FORMATS)))],
            'format' => ['required', 'string', Rule::in(EditorialPost::FORMATS)],
            'caption' => ['nullable', 'string', 'max:2200'],
            'hashtags' => ['nullable', 'array', 'max:30'],
            'hashtags.*' => ['string', 'max:60'],
            'cta' => ['nullable', 'string', 'max:300'],
        ], [
            'publish_date.after_or_equal' => 'A data de publicação não pode ser anterior a hoje.',
            'networks.required' => 'Escolha pelo menos uma rede.',
            'format.required' => 'Escolha o tipo de conteúdo.',
        ]);
        $play = $this->play($companyId, $data);
        $content = array_filter(['caption' => $data['caption'] ?? null, 'hashtags' => $data['hashtags'] ?? null, 'cta' => $data['cta'] ?? null], fn ($v) => $v !== null && $v !== '' && $v !== []);
        $post = $this->panel->createPost(Company::findOrFail($companyId), $data, $request->user(), $play['signal_keys'],
            ['media_formats' => array_filter((array) ($data['media_formats'] ?? [])), 'content' => $content]);

        return ApiResponse::success(
            ['post' => ['id' => $post->id, 'title' => $post->title, 'publish_date' => $post->publish_date->toDateString(), 'stage' => $post->stage]],
            'Ideia criada na Linha Editorial.'
        );
    }

    public function caption(Request $request, int $companyId)
    {
        $this->assertCanAct($request, $companyId);
        $data = $request->validate([
            'play_key' => ['required', 'string', 'max:160'],
            'location_id' => ['nullable', 'integer'],
            'networks' => ['required', 'array', 'min:1', 'max:2'],
            'networks.*' => ['string', Rule::in(EditorialPost::NETWORKS)],
        ]);
        $play = $this->play($companyId, $data);
        $formats = collect($play['where']['networks'])->pluck('format_key', 'network')->all();
        $r = app(CaptionAiService::class)->requestDraft(Company::with('contentSector')->findOrFail($companyId), $request->user(), [
            'play_key' => $play['key'], 'theme' => $play['theme'], 'brief' => $play['what']['text'], 'date' => $play['when']['date'],
            'networks' => $data['networks'], 'formats' => $formats,
        ]);

        return ApiResponse::success(app(AiRequestLifecycle::class)->present($r), 'Pedido enviado.', 202);
    }

    public function captionStatus(int $companyId, int $requestId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $r = AiRequest::where('company_id', $companyId)->where('mode', AiRequest::MODE_CAPTION)->whereNull('editorial_post_id')->find($requestId);
        if (! $r) {
            return ApiResponse::error('Pedido não encontrado.', 404);
        }

        return ApiResponse::success(app(AiRequestLifecycle::class)->present($r), 'Pedido carregado.');
    }

    /** A jogada atual com esta chave (as jogadas recalculam-se: se já não existir, diz isso). */
    private function play(int $companyId, array $data): array
    {
        $plays = $this->compass->payload($companyId, isset($data['location_id']) ? (int) $data['location_id'] : null, true)['plays'] ?? [];
        $play = collect($plays)->firstWhere('key', $data['play_key']);
        if (! $play) {
            abort(422, 'Esta jogada já não está entre as jogadas da semana (os sinais foram recalculados). Atualize a página.');
        }

        return $play;
    }

    private function assertCanAct(Request $request, int $companyId): void
    {
        if (! $this->authorizeCompany($companyId)) {
            abort(403, 'Acesso negado: utilizador inválido.');
        }
        [$can, $reason] = $this->panel->canAct($request->user(), $companyId);
        if (! $can) {
            abort(403, $reason);
        }
    }
}
