<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Helpers\ApiResponse;
use App\Services\Tenancy\CompanyAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Portão ÚNICO de tenancy das rotas /companies/{id}/… e /companies/{company}.
 *
 * O {id} da rota tem de ser uma empresa onde o utilizador pode trabalhar (CompanyAccess):
 * a própria, qualquer uma para o root, ou uma empresa que a agência dele gere com relação
 * ativa. Em impersonation o token é do utilizador-alvo, por isso fica limitado ao alvo.
 * Sem acesso: 403 com a mesma mensagem, exista ou não a empresa ou uma relação inativa.
 *
 * Corre antes do ensure_module (que lê o mesmo {id}) e não dispensa que os
 * controllers procurem os recursos filhos dentro da empresa (where company_id).
 * Assume auth:sanctum antes deste middleware.
 */
class EnsureTenantAccess
{
    public function __construct(private readonly CompanyAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $companyId = $request->route('id') ?? $request->route('company');

        // Rotas sem empresa na URL (ex.: GET /companies) não são deste portão.
        if ($companyId === null) {
            return $next($request);
        }

        if (! $this->access->allows($request->user(), $companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        return $next($request);
    }
}
