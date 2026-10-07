<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Helpers\ApiResponse;
use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Portão das rotas /agencies/{agency}/… (vista da agência): a empresa {agency} tem de ser
 * uma agência e a pessoa tem de ser dessa agência (ou o root). Os clientes que cada pessoa
 * vê decidem-se depois (relações ativas, atribuições e módulos: CompanyAccess::visibleManaged).
 */
class EnsureAgencyAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $agencyId = (int) $request->route('agency');
        $user = $request->user();
        $agency = $agencyId > 0 ? Company::whereKey($agencyId)->whereNotNull('agency_enabled_at')->first() : null;

        if (! $user || ! $agency || ($user->role !== 'root' && (int) $user->company_id !== $agency->id)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $request->attributes->set('agency', $agency);

        return $next($request);
    }
}
