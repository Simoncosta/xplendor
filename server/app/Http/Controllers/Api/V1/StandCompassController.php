<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\AiRequest;
use App\Models\Car;
use App\Models\Company;
use App\Models\EditorialPost;
use App\Services\Ai\AiRequestLifecycle;
use App\Services\Compass\CompassAccess;
use App\Services\Compass\Stand\StandCompassActions;
use App\Services\Compass\Stand\StandCompassProvider;
use App\Services\Compass\Stand\StandCompassService;
use App\Services\Editorial\CaptionAiService;
use App\Services\Editorial\NetworkFormats;
use App\Support\PricePosition;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Bússola do stand (veículos: carros e autocaravanas), no módulo de stock:
 *  · GET  .../marketing/bussola-stand?type=&plays_only=   a página (ou só as jogadas, para o dashboard)
 *  · POST .../marketing/bussola-stand/post                 "Criar publicação" a partir de uma jogada
 *  · POST .../marketing/bussola-stand/caption              "Sugerir texto" (com os dados do veículo)
 *  · GET  .../marketing/bussola-stand/caption/{requestId}  estado das propostas
 *  · POST .../marketing/bussola-stand/ignore | restore | exclude
 * Ver: bussola.ver (sem a área automóvel: a Bússola não mostra dados pessoais). Criar, sugerir
 * e ignorar: quem produz na Linha Editorial (como na restauração).
 */
class StandCompassController extends Controller
{
    public function __construct(
        private readonly StandCompassProvider $provider,
        private readonly StandCompassService $compass,
        private readonly StandCompassActions $actions,
        private readonly CompassAccess $access,
    ) {}

    public function show(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $data = $request->validate(['type' => ['nullable', Rule::in(array_keys(StandCompassService::TYPES))], 'plays_only' => ['nullable', 'boolean']]);

        return ApiResponse::success($this->provider->pageFor($request->user(), $companyId, $data), 'Bússola do stand.');
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
        $res = $this->actions->createPost(Company::findOrFail($companyId), $request->user(), $play, $data,
            ['media_formats' => array_filter((array) ($data['media_formats'] ?? [])), 'content' => $content]);
        $post = $res['post'];

        return ApiResponse::success(
            ['post' => ['id' => $post->id, 'title' => $post->title, 'publish_date' => $post->publish_date->toDateString(), 'stage' => $post->stage], 'photos' => $res['photos']],
            $res['photos'] > 0 ? "Ideia criada na Linha Editorial, com {$res['photos']} " . ($res['photos'] === 1 ? 'fotografia' : 'fotografias') . ' do veículo.' : 'Ideia criada na Linha Editorial.'
        );
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
            'play_key' => $play['key'], 'theme' => $play['theme'], 'brief' => $play['what']['text'] . $this->vehicleFacts($companyId, $play),
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
        $carId = $this->actions->exclude($companyId, $request->user(), $data['key']);

        return ApiResponse::success(['car_id' => $carId], 'O veículo não volta a ser sugerido.');
    }

    /** Os dados do veículo para a legenda (marca, modelo, ano, quilómetros e preço), sem dados pessoais. */
    private function vehicleFacts(int $companyId, array $play): string
    {
        $car = ! empty($play['car_id']) ? Car::with(['brand', 'model'])->where('company_id', $companyId)->find($play['car_id']) : null;
        if (! $car) {
            return '';
        }
        $price = PricePosition::effectivePriceForCar($car);

        return sprintf(' Veículo: %s %s, %s, %s, %s.', $car->brand?->name, $car->model?->name, $car->registration_year ?: 'ano por indicar',
            $car->mileage_km !== null ? number_format((int) $car->mileage_km, 0, ',', ' ') . ' km' : 'quilómetros por indicar',
            $price !== null ? number_format($price, 0, ',', ' ') . ' €' : 'preço por indicar');
    }

    /** A jogada atual com esta chave (as jogadas recalculam-se: se já não existir, diz isso). */
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
