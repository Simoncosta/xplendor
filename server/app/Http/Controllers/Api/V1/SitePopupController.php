<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Access\Access;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\SitePopup;
use App\Services\SitePopups\SitePopupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * "Avisos do site" no painel (área "avisos_site"; as permissões de cada rota estão em
 * RoutePermissions). Só os avisos da empresa da rota (de outra empresa: 404).
 */
class SitePopupController extends Controller
{
    public function __construct(private readonly SitePopupService $popups, private readonly Access $access) {}

    // GET /companies/{id}/site-popups
    public function index(Request $request, int $companyId): JsonResponse
    {
        $all = SitePopup::where('company_id', $companyId)->orderByDesc('priority')->orderByDesc('id')->get();
        $can = fn (string $p) => $this->access->can($request->user(), $companyId, $p)->allowed;

        return ApiResponse::success([
            'popups' => $all->map(fn (SitePopup $p) => $this->popups->presentAdmin($p, $all))->values(),
            'can' => ['create' => $can('avisos_site.criar'), 'edit' => $can('avisos_site.editar'), 'delete' => $can('avisos_site.apagar')],
            'options' => ['pages' => SitePopup::PAGES, 'frequencies' => SitePopup::FREQUENCIES],
        ], 'Avisos do site.');
    }

    // GET /companies/{id}/site-popups/{popup}
    public function show(int $companyId, int $popupId): JsonResponse
    {
        $popup = SitePopup::where('company_id', $companyId)->findOrFail($popupId);
        $all = SitePopup::where('company_id', $companyId)->get();

        return ApiResponse::success(['stats' => $this->popups->stats($popup, 30, true)] + $this->popups->presentAdmin($popup, $all), 'Aviso.');
    }

    // POST /companies/{id}/site-popups
    public function store(Request $request, int $companyId): JsonResponse
    {
        $popup = $this->popups->save($companyId, $request->user(), $this->validated($request));

        return ApiResponse::success($this->popups->presentAdmin($popup, SitePopup::where('company_id', $companyId)->get()), 'Aviso criado.', 201);
    }

    // PUT /companies/{id}/site-popups/{popup}
    public function update(Request $request, int $companyId, int $popupId): JsonResponse
    {
        $popup = SitePopup::where('company_id', $companyId)->findOrFail($popupId);
        $popup = $this->popups->save($companyId, $request->user(), $this->validated($request), $popup);

        return ApiResponse::success($this->popups->presentAdmin($popup, SitePopup::where('company_id', $companyId)->get()), 'Aviso guardado.');
    }

    // DELETE /companies/{id}/site-popups/{popup}
    public function destroy(int $companyId, int $popupId): JsonResponse
    {
        $this->popups->delete(SitePopup::where('company_id', $companyId)->findOrFail($popupId));

        return ApiResponse::success(null, 'Aviso apagado.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['nullable', 'string', 'max:1000'],
            'button_label' => ['nullable', 'required_with:button_url', 'string', 'max:60'],
            // https:// ou um caminho do próprio site ("/viaturas"); nunca javascript: nem http.
            'button_url' => ['nullable', 'required_with:button_label', 'string', 'max:500', 'regex:#^(https://[^\s]+|/[^\s/][^\s]*|/)$#i'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'pages' => ['required', 'array', 'min:1'],
            'pages.*' => [Rule::in(SitePopup::PAGES)],
            'delay_seconds' => ['nullable', 'integer', 'min:0', 'max:120'],
            'frequency' => ['required', Rule::in(SitePopup::FREQUENCIES)],
            'priority' => ['nullable', 'integer', 'min:-100', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
            'image_file_id' => ['nullable', 'integer'],
            'remove_image' => ['nullable', 'boolean'],
        ], [
            'button_url.regex' => 'A ligação do botão tem de começar por https:// ou ser um caminho do site (por exemplo, /viaturas).',
            'ends_at.after' => 'O fim tem de ser depois do início.',
            'pages.required' => 'Escolha onde o aviso aparece.',
        ]);
    }
}
