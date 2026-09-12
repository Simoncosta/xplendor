<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\SatisfactionReport;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * DMS — Resolve o relatório de satisfação pelo public_token do URL.
 *
 * Análogo ao check_company_api_token, mas POR-RELATÓRIO (não por-empresa): a
 * chave de acesso é o token individual do link. 404 se não existir ou expirado
 * — nunca revela se o token é inválido ou apenas caducou (não dá pistas a quem
 * tenta adivinhar). Injecta o relatório resolvido no request.
 */
class ResolveReportToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->route('token');

        $report = strlen($token) > 0
            ? SatisfactionReport::where('public_token', $token)->first()
            : null;

        if (! $report || $report->isExpired()) {
            abort(404);
        }

        $request->merge(['satisfaction_report' => $report]);

        return $next($request);
    }
}
