<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\EditorialPost;
use App\Services\Compass\CompassAccess;
use App\Services\Editorial\NetworkFormats;
use App\Services\Radar\Calendar\OfficialDates;
use App\Services\Radar\RadarSignalActions;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Bússola 5: o "Vem aí" (as datas oficiais do ramo e do concelho) sem o módulo radar. Fica no módulo
 * linha_editorial, porque "Reagir" cria uma ideia na Linha Editorial; permissões da área bussola:
 * ver (bussola.ver) e reagir (bussola.criar, e quem produz na Linha Editorial, como no Radar).
 */
class CompassCalendarController extends Controller
{
    public function __construct(
        private readonly RadarSignalActions $actions,
        private readonly CompassAccess $access,
    ) {}

    public function show(Request $request, int $companyId)
    {
        [$canAct, $reason] = $this->access->canAct($request->user(), $companyId);

        return ApiResponse::success([
            'calendar' => OfficialDates::upcomingFor($companyId, CarbonImmutable::now()),
            'can_act' => $canAct, 'can_act_reason' => $reason,
            'formats' => EditorialPost::FORMATS,
        ], 'Datas que vêm aí.');
    }

    public function react(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            abort(403, 'Acesso negado: utilizador inválido.');
        }
        [$can, $reason] = $this->access->canAct($request->user(), $companyId);
        if (! $can) {
            abort(403, $reason);
        }
        $today = CarbonImmutable::now('Europe/Lisbon')->toDateString();
        $data = $request->validate([
            'calendar_key' => ['required', 'string', 'max:80'],
            'occurrence' => ['required', 'date_format:Y-m-d'],
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
        $idea = ['title' => $data['title'], 'publish_date' => $data['publish_date'], 'networks' => $data['networks'], 'format' => $data['format'],
            'media_formats' => array_filter((array) ($data['media_formats'] ?? []))];
        $post = $this->actions->reactToDate(Company::findOrFail($companyId), $request->user(), $data['calendar_key'], $data['occurrence'], $idea);

        return ApiResponse::success(
            ['post' => ['id' => $post->id, 'title' => $post->title, 'publish_date' => $post->publish_date->toDateString(), 'stage' => $post->stage]],
            'Ideia criada na Linha Editorial, com o gancho e as fontes, sem texto.'
        );
    }
}
