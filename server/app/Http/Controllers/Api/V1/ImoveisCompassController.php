<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\AiRequest;
use App\Models\Company;
use App\Models\EditorialPost;
use App\Models\Property;
use App\Services\Ai\AiRequestLifecycle;
use App\Services\Compass\CompassAccess;
use App\Services\Compass\Imoveis\ImoveisCompassActions;
use App\Services\Compass\Imoveis\ImoveisCompassProvider;
use App\Services\Compass\Imoveis\ImoveisCompassService;
use App\Services\Editorial\CaptionAiService;
use App\Services\Editorial\NetworkFormats;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Bússola do imobiliário (Bússola Master, R3), no módulo imoveis, com as permissões da área
 * bussola, como a do stand. Agir (criar, sugerir, ignorar) pede ainda quem produz na Linha
 * Editorial.
 */
class ImoveisCompassController extends Controller
{
    public function __construct(
        private readonly ImoveisCompassProvider $provider,
        private readonly ImoveisCompassService $compass,
        private readonly ImoveisCompassActions $actions,
        private readonly CompassAccess $access,
    ) {}

    public function show(Request $request, int $companyId)
    {
        $data = $request->validate(['type' => ['nullable', 'string', Rule::in(array_keys(ImoveisCompassService::TYPES))], 'plays_only' => ['nullable', 'boolean']]);

        return ApiResponse::success($this->provider->pageFor($request->user(), $companyId, ['type' => $data['type'] ?? 'all', 'plays_only' => (bool) ($data['plays_only'] ?? false)]),
            'Bússola do imobiliário.');
    }

    public function createPost(Request $request, int $companyId)
    {
        $this->assertCanAct($request, $companyId);
        $today = CarbonImmutable::now('Europe/Lisbon')->toDateString();
        $data = $request->validate([
            'play_key' => ['required', 'string', 'max:160'],
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
        $play = $this->play($request, $companyId, $data['play_key']);
        $content = array_filter(['caption' => $data['caption'] ?? null, 'hashtags' => $data['hashtags'] ?? null, 'cta' => $data['cta'] ?? null], fn ($v) => $v !== null && $v !== '' && $v !== []);
        $post = $this->actions->createPost(Company::findOrFail($companyId), $request->user(), $play, $data,
            ['media_formats' => array_filter((array) ($data['media_formats'] ?? [])), 'content' => $content]);

        return ApiResponse::success(['post' => ['id' => $post->id, 'title' => $post->title, 'publish_date' => $post->publish_date->toDateString(), 'stage' => $post->stage], 'photos' => 0],
            'Ideia criada na Linha Editorial.');
    }

    public function caption(Request $request, int $companyId)
    {
        $this->assertCanAct($request, $companyId);
        $data = $request->validate([
            'play_key' => ['required', 'string', 'max:160'],
            'networks' => ['required', 'array', 'min:1', 'max:2'],
            'networks.*' => ['string', Rule::in(EditorialPost::NETWORKS)],
        ]);
        $play = $this->play($request, $companyId, $data['play_key']);
        $formats = collect($play['where']['networks'])->pluck('format_key', 'network')->all();
        $r = app(CaptionAiService::class)->requestDraft(Company::with('contentSector')->findOrFail($companyId), $request->user(), [
            'play_key' => $play['key'], 'theme' => $play['theme'], 'brief' => $play['what']['text'] . $this->propertyFacts($companyId, $play),
            'date' => $play['when']['date'], 'networks' => $data['networks'], 'formats' => $formats,
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

    public function ignore(Request $request, int $companyId)
    {
        $this->assertCanAct($request, $companyId);
        $data = $request->validate(['keys' => ['required', 'array', 'min:1', 'max:50'], 'keys.*' => ['string', 'max:160']]);
        $this->actions->ignore($companyId, $request->user(), $data['keys']);

        return ApiResponse::success(['ignored' => count($data['keys'])], 'Escondido durante 4 semanas.');
    }

    public function restore(Request $request, int $companyId)
    {
        $this->assertCanAct($request, $companyId);
        $data = $request->validate(['key' => ['required', 'string', 'max:160']]);
        $this->actions->restore($companyId, $request->user(), $data['key']);

        return ApiResponse::success(['restored' => $data['key']], 'Sugestão restaurada.');
    }

    public function exclude(Request $request, int $companyId)
    {
        $this->assertCanAct($request, $companyId);
        $data = $request->validate(['key' => ['required', 'string', 'max:160']]);

        return ApiResponse::success(['property_id' => $this->actions->exclude($companyId, $request->user(), $data['key'])], 'O imóvel não volta a ser sugerido.');
    }

    /** Os dados do imóvel para a legenda (tipologia, tipo, freguesia, área, preço, classe energética), sem dados de pessoas. */
    private function propertyFacts(int $companyId, array $play): string
    {
        $p = ! empty($play['property_id']) ? Property::with('parish')->where('company_id', $companyId)->find($play['property_id']) : null;
        if (! $p) {
            return '';
        }

        return sprintf(' Imóvel: %s %s, %s, %s, %s, classe energética %s.', Property::KINDS[$p->kind] ?? 'Imóvel', $p->typology ?? '', $p->parish?->name ?? 'freguesia por indicar',
            Property::areaLabel($p->area_m2) ?? 'área por indicar', number_format($p->price, 0, ',', ' ') . ($p->listing === 'arrendamento' ? ' € por mês' : ' €'),
            $p->energyClassMissing() ? 'por indicar' : $p->energy_class);
    }

    private function play(Request $request, int $companyId, string $key): array
    {
        $scope = explode(':', $key)[1] ?? 'all';
        $plays = $this->compass->payload($companyId, $request->user(), $scope, true, CompassAccess::seesFinance($request->user(), $companyId))['plays'] ?? [];
        $play = collect($plays)->firstWhere('key', $key);
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
        [$can, $reason] = $this->access->canAct($request->user(), $companyId);
        if (! $can) {
            abort(403, $reason);
        }
    }
}
