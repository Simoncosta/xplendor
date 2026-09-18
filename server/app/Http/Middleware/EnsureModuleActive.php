<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\CompanyModuleService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * XPLENDOR — Fase 3: gate de SEGURANÇA por módulo. Recusa (403) se o módulo
 * exigido pela rota não estiver ATIVO na empresa. É a fronteira real (o esconder
 * no menu da Fase 2 é só UX — quem souber o URL entrava na mesma).
 *
 * Usa o mesmo conceito de módulos da Fase 2 (CompanyModuleService/ModuleRegistry)
 * — menu e rotas alinhados. Uso: ->middleware('ensure_module:stock').
 *
 * A empresa vem do parâmetro {id} da rota (o prefixo /companies/{id}); em fallback,
 * a empresa do utilizador. Root faz bypass (vê tudo). Assume auth:sanctum antes.
 */
class EnsureModuleActive
{
    public function __construct(private readonly CompanyModuleService $modules) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        $user = Auth::user();

        // Root vê tudo — não filtra por módulos.
        if ($user && $user->role === 'root') {
            return $next($request);
        }

        $companyId = (int) ($request->route('id') ?? $user?->company_id ?? 0);
        if ($companyId <= 0) {
            abort(403, 'Empresa inválida.');
        }

        if (! $this->modules->isEnabled($companyId, $module)) {
            abort(403, 'Este módulo não está ativo para a empresa.');
        }

        return $next($request);
    }
}
