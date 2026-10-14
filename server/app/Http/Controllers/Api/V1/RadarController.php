<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\EditorialPost;
use App\Models\RadarSetting;
use App\Services\Compass\CompassAccess;
use App\Services\Editorial\NetworkFormats;
use App\Services\Radar\RadarPage;
use App\Services\Radar\RadarSignalActions;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Radar de oportunidade (Bússola Master, R2), no módulo radar e com as permissões da área
 * bussola: ver (bussola.ver), reagir (bussola.criar), ignorar, silenciar e mudar as definições
 * (bussola.editar). Reagir e ignorar pedem ainda quem produz na Linha Editorial (CompassAccess),
 * como a Bússola do stand.
 */
class RadarController extends Controller
{
    public function __construct(
        private readonly RadarPage $page,
        private readonly RadarSignalActions $actions,
        private readonly CompassAccess $access,
    ) {}

    public function show(Request $request, int $companyId)
    {
        return ApiResponse::success($this->page->payload($companyId, $request->user()), 'Radar.');
    }

    public function react(Request $request, int $companyId)
    {
        $this->assertCanAct($request, $companyId);
        $today = CarbonImmutable::now('Europe/Lisbon')->toDateString();
        $data = $request->validate([
            'signal_id' => ['required_without:calendar_key', 'nullable', 'integer'],
            'calendar_key' => ['required_without:signal_id', 'nullable', 'string', 'max:80'],
            'occurrence' => ['required_with:calendar_key', 'nullable', 'date_format:Y-m-d'],
            'title' => ['required', 'string', 'max:255'],
            'publish_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:' . $today],
            'networks' => ['required', 'array', 'min:1', 'max:2'],
            'networks.*' => ['string', Rule::in(EditorialPost::NETWORKS)],
            'media_formats' => ['nullable', 'array'],
            'media_formats.*' => ['nullable', 'string', Rule::in(array_merge(...array_values(NetworkFormats::FORMATS)))],
            'format' => ['required', 'string', Rule::in(EditorialPost::FORMATS)],
        ], [
            'publish_date.after_or_equal' => 'A data de publicação não pode ser anterior a hoje.',
            'networks.required' => 'Escolha pelo menos uma rede.',
            'format.required' => 'Escolha o tipo de conteúdo.',
        ]);
        $company = Company::findOrFail($companyId);
        $idea = ['title' => $data['title'], 'publish_date' => $data['publish_date'], 'networks' => $data['networks'], 'format' => $data['format'],
            'media_formats' => array_filter((array) ($data['media_formats'] ?? []))];
        $post = ! empty($data['signal_id'])
            ? $this->actions->react($company, $request->user(), $this->actions->signal($companyId, (int) $data['signal_id']), $idea)
            : $this->actions->reactToDate($company, $request->user(), (string) $data['calendar_key'], (string) $data['occurrence'], $idea);

        return ApiResponse::success(
            ['post' => ['id' => $post->id, 'title' => $post->title, 'publish_date' => $post->publish_date->toDateString(), 'stage' => $post->stage]],
            'Ideia criada na Linha Editorial, com o gancho e as fontes, sem texto.'
        );
    }

    public function ignore(Request $request, int $companyId)
    {
        $this->assertCanAct($request, $companyId);
        $data = $request->validate(['signal_id' => ['required', 'integer']]);
        $this->actions->ignore($this->actions->signal($companyId, (int) $data['signal_id']), $request->user());

        return ApiResponse::success(null, 'Sugestão ignorada.');
    }

    public function mute(Request $request, int $companyId)
    {
        $this->assertCanAct($request, $companyId);
        $data = $request->validate(['signal_id' => ['required', 'integer']]);
        $this->actions->mute($this->actions->signal($companyId, (int) $data['signal_id']), $request->user());

        return ApiResponse::success(null, 'Temas como este deixam de aparecer. Pode voltar a mostrá-los nas definições do Radar.');
    }

    public function unmute(Request $request, int $companyId)
    {
        $data = $request->validate(['index' => ['required', 'integer', 'min:0']]);
        $this->actions->unmute($companyId, (int) $data['index'], $request->user());

        return ApiResponse::success($this->page->payload($companyId, $request->user())['settings'], 'O tema volta a aparecer.');
    }

    public function settings(Request $request, int $companyId)
    {
        return ApiResponse::success($this->page->payload($companyId, $request->user())['settings'], 'Definições do Radar.');
    }

    public function updateSettings(Request $request, int $companyId)
    {
        $data = $request->validate([
            'sports_enabled' => ['required', 'boolean'],
            'sports_clubs' => ['nullable', 'array', 'max:20'],
            'sports_clubs.*' => ['string', 'min:2', 'max:40'],
        ], ['sports_clubs.max' => 'No máximo 20 clubes.', 'sports_clubs.*.max' => 'Cada clube tem no máximo 40 caracteres.']);
        $clubs = array_values(array_unique(array_filter(array_map(fn ($c) => trim((string) $c), (array) ($data['sports_clubs'] ?? [])))));
        RadarSetting::updateOrCreate(['company_id' => $companyId], [
            'sports_enabled' => (bool) $data['sports_enabled'], 'sports_clubs' => $clubs, 'updated_by_user_id' => $request->user()->id,
        ]);

        return ApiResponse::success($this->page->payload($companyId, $request->user())['settings'], 'Definições do Radar guardadas.');
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
