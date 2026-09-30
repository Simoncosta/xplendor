<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ImpersonationSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * XPLENDOR — IMPERSONATION: bloqueia AÇÕES SENSÍVEIS quando a request é impersonation.
 * Fonte de verdade no BACKEND (não confiar no frontend esconder o botão). Aplica-se nas
 * rotas sensíveis: credenciais de integrações, apagar/anular destrutivo, troca de ramo,
 * gestão de utilizadores/password, revogar tokens, apagar conta. Assume auth:sanctum antes.
 */
class BlockWhenImpersonating
{
    public function handle(Request $request, Closure $next): Response
    {
        if (ImpersonationSession::activeFor($request->user())) {
            abort(403, 'Esta ação não é permitida durante a impersonation. Sai da impersonation para a executar.');
        }

        return $next($request);
    }
}
