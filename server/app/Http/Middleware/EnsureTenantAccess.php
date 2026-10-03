<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Helpers\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Portão ÚNICO de tenancy das rotas /companies/{id}/… e /companies/{company}.
 *
 * O {id} da rota tem de ser a empresa do utilizador autenticado; só o root
 * (dono da plataforma) passa para qualquer empresa. Em impersonation o token é
 * do utilizador-alvo, por isso fica naturalmente limitado à empresa desse alvo.
 *
 * Corre antes do ensure_module (que lê o mesmo {id}) e não dispensa que os
 * controllers procurem os recursos filhos dentro da empresa (where company_id).
 * Assume auth:sanctum antes deste middleware.
 */
class EnsureTenantAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $companyId = $request->route('id') ?? $request->route('company');

        // Rotas sem empresa na URL (ex.: GET /companies) não são deste portão.
        if ($companyId === null) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user || ((int) $user->company_id !== (int) $companyId && $user->role !== 'root')) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        return $next($request);
    }
}
