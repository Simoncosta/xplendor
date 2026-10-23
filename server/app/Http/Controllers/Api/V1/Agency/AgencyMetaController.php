<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Agency;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Agency\AgencyMetaConnectionService;
use App\Services\Tenancy\CompanyAccess;
use Illuminate\Http\Request;

/**
 * Parceiro 2 (/agencies/{agency}/meta, middleware "agency"): a ligação da Meta da agência. Quem é da
 * agência vê o estado; só o administrador da agência (não o root) liga e desliga. Nunca sai o token.
 */
class AgencyMetaController extends Controller
{
    public function __construct(private readonly AgencyMetaConnectionService $connections) {}

    private function agency(Request $request): Company
    {
        return $request->attributes->get('agency');
    }

    private function assertAgencyAdmin(Request $request, string $message): void
    {
        $user = $request->user();
        abort_unless(! $user->isRoot() && app(CompanyAccess::class)->isAgencyAdmin($user, $this->agency($request)->id), 403, $message);
    }

    // GET /agencies/{agency}/meta
    public function show(Request $request)
    {
        return ApiResponse::success($this->connections->status($this->agency($request)->id), 'Ligação da Meta da agência.');
    }

    // GET /agencies/{agency}/meta/auth-url
    public function authUrl(Request $request)
    {
        $this->assertAgencyAdmin($request, 'Só o administrador da agência liga a Meta da agência.');

        return ApiResponse::success(['url' => $this->connections->authUrl($this->agency($request), $request->user())], 'Abra o endereço para autorizar a Meta.');
    }

    // DELETE /agencies/{agency}/meta
    public function disconnect(Request $request)
    {
        $this->assertAgencyAdmin($request, 'Só o administrador da agência desliga a Meta da agência.');
        $out = $this->connections->disconnect($this->agency($request)->id);

        return ApiResponse::success($out + ['meta' => $this->connections->status($this->agency($request)->id)],
            $out['kept_shared'] === [] ? 'Ligação da Meta desligada e permissões retiradas na Meta.'
                : 'Ligação da Meta desligada. Algumas permissões ficaram na Meta porque outras ligações do mesmo utilizador as usam.');
    }
}
