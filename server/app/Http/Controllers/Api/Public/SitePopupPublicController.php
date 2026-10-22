<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\SitePopup;
use App\Services\SitePopups\SitePopupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Avisos (pop-up) para os sites dos clientes:
 *  · sites dos stands (a partir do browser, com o token público): GET /api/public/popup e
 *    POST /api/public/popup/{id}/event;
 *  · sites dos consultores (servidor a servidor): os mesmos em /api/public/site/v1.
 * A lista vem numa só chamada, por prioridade, cada aviso com as suas páginas; o site mostra o
 * primeiro que se aplica à página e cumpre a frequência (localStorage ou sessionStorage).
 * As vistas, os cliques e os fechos contam-se só como números: sem IP, sem cookies.
 */
class SitePopupPublicController extends Controller
{
    public function __construct(private readonly SitePopupService $popups) {}

    // GET /api/public/popup?token=…
    public function index(Request $request): JsonResponse
    {
        return $this->list($this->companyId($request));
    }

    // POST /api/public/popup/{popup}/event?token=…   { type: view|click|close }
    public function event(Request $request, int $popup): Response
    {
        return $this->count($this->companyId($request), $popup, $request);
    }

    // GET /api/public/site/v1/popup
    public function siteIndex(Request $request): JsonResponse
    {
        return $this->list((int) $request->attributes->get('consultant_site')->company_id);
    }

    // POST /api/public/site/v1/popup/{popup}/event
    public function siteEvent(Request $request, int $popup): Response
    {
        if ($request->attributes->get('site_preview')) {
            return response()->noContent(); // pré-visualização: não conta
        }

        return $this->count((int) $request->attributes->get('consultant_site')->company_id, $popup, $request);
    }

    private function list(int $companyId): JsonResponse
    {
        $popups = $this->popups->live($companyId)->map(fn (SitePopup $p) => SitePopupService::publicShape($p))->values();

        return response()->json(['popups' => $popups])->header('Cache-Control', 'public, max-age=60');
    }

    private function count(int $companyId, int $popupId, Request $request): Response
    {
        $type = (string) $request->input('type');
        if (! in_array($type, ['view', 'click', 'close'], true)) {
            return response(['message' => 'O tipo tem de ser view, click ou close.', 'code' => 'invalid_type'], 422);
        }
        $popup = SitePopup::where('company_id', $companyId)->live()->find($popupId);
        if (! $popup) {
            return response(['message' => 'Aviso não encontrado.', 'code' => 'not_found'], 404);
        }
        $this->popups->record($popup, $type);

        return response()->noContent();
    }

    private function companyId(Request $request): int
    {
        $company = $request->input('public_api_company');

        return $company instanceof Company ? (int) $company->id : 0;
    }
}
