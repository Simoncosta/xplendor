<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Agency;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\AgencyAssetLink;
use App\Models\Company;
use App\Services\Agency\Meta\AgencyAssetLinkService;
use App\Services\Tenancy\CompanyAccess;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Parceiro 4 (/agencies/{agency}/meta/links, middleware "agency"): ligar os ativos da Meta da agência a
 * uma empresa gerida. Só o administrador da agência (e o root, para ver); ligar e desligar só o
 * administrador da agência, e só para clientes ativos desta agência (qualquer outra empresa: 403).
 */
class AgencyMetaLinksController extends Controller
{
    public function __construct(private readonly AgencyAssetLinkService $links) {}

    private function agency(Request $request): Company
    {
        return $request->attributes->get('agency');
    }

    // GET /agencies/{agency}/meta/links
    public function index(Request $request)
    {
        abort_unless(app(CompanyAccess::class)->isAgencyAdmin($request->user(), $this->agency($request)->id), 403,
            'Só o administrador da agência vê os ativos ligados aos clientes.');

        return ApiResponse::success($this->links->list($this->agency($request)->id), 'Ativos da Meta ligados aos clientes.');
    }

    // POST /agencies/{agency}/meta/links {company_id, asset_type, external_id, replace?}
    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless(! $user->isRoot() && app(CompanyAccess::class)->isAgencyAdmin($user, $this->agency($request)->id), 403,
            'Só o administrador da agência liga os ativos aos clientes.');
        $data = $request->validate([
            'company_id' => ['required', 'integer'],
            'asset_type' => ['required', Rule::in(AgencyAssetLink::TYPES)],
            'external_id' => ['required', 'string', 'max:64', 'regex:/^[0-9]+$/'],
            'replace' => ['nullable', 'boolean'],
        ]);
        $link = $this->links->link($this->agency($request)->id, (int) $data['company_id'], $data['asset_type'], $data['external_id'], $user, (bool) ($data['replace'] ?? false));

        return ApiResponse::success($link, $link['status'] === AgencyAssetLink::LINKED ? 'Ativo ligado à empresa.' : AgencyAssetLinkService::NO_TASK_MESSAGE);
    }

    // DELETE /agencies/{agency}/meta/links/{link}
    public function destroy(Request $request, int $agencyId, int $link)
    {
        $user = $request->user();
        abort_unless(! $user->isRoot() && app(CompanyAccess::class)->isAgencyAdmin($user, $this->agency($request)->id), 403,
            'Só o administrador da agência desliga os ativos dos clientes.');
        $this->links->unlink($this->agency($request)->id, $link);

        return ApiResponse::success(null, 'Ativo desligado da empresa.');
    }
}
