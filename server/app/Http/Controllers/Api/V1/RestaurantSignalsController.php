<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\EditorialPost;
use App\Services\Restaurant\RestaurantSignalPanelService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * XPLENDOR — F3: "O que publicar e quando" (documents/PINGWIN-F3-DESENHO.md).
 *  · GET  .../pingwin/signals            painel (qualquer utilizador da empresa);
 *  · POST .../pingwin/signals/ignore     esconde 4 semanas (quem produz na Linha Editorial);
 *  · POST .../pingwin/signals/restore    volta a mostrar (idem);
 *  · POST .../pingwin/signals/post       cria uma ideia na Linha Editorial (idem).
 */
class RestaurantSignalsController extends Controller
{
    public function __construct(private readonly RestaurantSignalPanelService $panel) {}

    // GET /companies/{id}/integrations/pingwin/signals?location_id=&show_ignored=
    public function index(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $data = $request->validate([
            'location_id' => ['nullable', 'integer'],
            'show_ignored' => ['nullable', 'boolean'],
        ]);

        return ApiResponse::success($this->panel->panel(
            $companyId, $request->user(),
            isset($data['location_id']) ? (int) $data['location_id'] : null,
            (bool) ($data['show_ignored'] ?? false),
        ), 'O que publicar e quando.');
    }

    // POST /companies/{id}/integrations/pingwin/signals/ignore   Body: { key }
    public function ignore(Request $request, int $companyId)
    {
        $this->assertCanAct($request, $companyId);
        $data = $request->validate(['key' => ['required', 'string', 'max:160']]);
        $until = $this->panel->ignore($companyId, $data['key'], $request->user());

        return ApiResponse::success(['hidden_until' => $until], 'Sugestão escondida durante 4 semanas.');
    }

    // POST /companies/{id}/integrations/pingwin/signals/restore   Body: { key }
    public function restore(Request $request, int $companyId)
    {
        $this->assertCanAct($request, $companyId);
        $data = $request->validate(['key' => ['required', 'string', 'max:160']]);
        $this->panel->restore($companyId, $data['key'], $request->user());

        return ApiResponse::success(['restored' => true], 'Sugestão de volta à lista.');
    }

    // POST /companies/{id}/integrations/pingwin/signals/post   Body: { key, title, publish_date, networks[], format }
    public function createPost(Request $request, int $companyId)
    {
        $this->assertCanAct($request, $companyId);
        $data = $request->validate([
            'key' => ['required', 'string', 'max:160'],
            'title' => ['required', 'string', 'max:255'],
            'publish_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:' . \Carbon\CarbonImmutable::now('Europe/Lisbon')->toDateString()],
            'networks' => ['required', 'array', 'min:1', 'max:2'],
            'networks.*' => ['string', Rule::in(EditorialPost::NETWORKS)],
            'format' => ['required', 'string', Rule::in(EditorialPost::FORMATS)],
        ], [
            'networks.required' => 'Escolha pelo menos uma rede.',
            'format.required' => 'Escolha o tipo de conteúdo.',
            'publish_date.after_or_equal' => 'A data de publicação não pode ser anterior a hoje.',
        ]);

        $post = $this->panel->createPost(Company::findOrFail($companyId), $data, $request->user());

        return ApiResponse::success(
            ['post' => ['id' => $post->id, 'title' => $post->title, 'publish_date' => $post->publish_date->toDateString(), 'stage' => $post->stage]],
            'Ideia criada na Linha Editorial.'
        );
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
