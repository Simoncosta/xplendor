<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * DMS — Portão ÚNICO do super-admin (dono da plataforma, role 'root').
 *
 * É o único ponto de verdade para "és super-admin". Todo o acesso TRANSVERSAL
 * (que vê dados de todas as empresas, furando o tenancy de propósito) vive
 * atrás deste middleware, no grupo /api/v1/admin. Assim o contorno do
 * isolamento por empresa acontece num só sítio controlado — nunca espalhado.
 *
 * Assume auth:sanctum antes deste middleware (utilizador já autenticado).
 */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (! $user || $user->role !== 'root') {
            abort(403, 'Acesso restrito ao administrador da plataforma.');
        }

        return $next($request);
    }
}
