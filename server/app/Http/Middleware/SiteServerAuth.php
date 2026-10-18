<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ConsultantSiteHost;
use App\Models\SiteServerToken;
use App\Services\CompanyModuleService;
use App\Services\ConsultantSite\SitePreview;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API do site do consultor (servidor a servidor). O contentor do site envia o seu token
 * (Authorization: Bearer) e o host do pedido do visitante (X-Site-Host). Aqui:
 *  1. o token existe (só o hash está na base), não foi revogado: senão 401;
 *  2. o host pertence a um site ligado a esse token, a empresa tem o módulo site_consultor e o
 *     site está publicado, ou o pedido traz uma pré-visualização válida (X-Site-Preview, link
 *     assinado e temporário): senão 404 (o site mostra a página 404; não se diz porquê).
 * O site e a empresa ficam no pedido (consultant_site). Nunca usa a sessão nem cookies.
 */
class SiteServerAuth
{
    public function __construct(private readonly CompanyModuleService $modules) {}

    public function handle(Request $request, Closure $next): Response
    {
        $plain = (string) $request->bearerToken();
        $token = $plain === '' ? null : SiteServerToken::where('token_hash', SiteServerToken::hash($plain))->whereNull('revoked_at')->first();
        if (! $token) {
            return response()->json(['success' => false, 'message' => 'Não autorizado.'], 401);
        }

        $host = ConsultantSiteHost::normalize((string) $request->header('X-Site-Host', ''));
        $site = $host === '' ? null : $token->sites()->whereHas('hosts', fn ($q) => $q->where('host', $host))->with('hosts')->first();
        $preview = $site !== null && $request->hasHeader('X-Site-Preview') && SitePreview::valid((string) $request->header('X-Site-Preview'), $site);
        if (! $site || ($site->site_status !== 'publicado' && ! $preview) || ! $this->modules->isEnabled((int) $site->company_id, 'site_consultor')) {
            return response()->json(['success' => false, 'message' => 'Site não encontrado.'], 404);
        }

        if (! $token->last_used_at || $token->last_used_at->lt(now()->subMinutes(10))) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }
        $request->attributes->set('consultant_site', $site);
        $request->attributes->set('site_preview', $preview);

        $response = $next($request);
        if ($preview) {
            // A pré-visualização nunca fica em cache partilhada nem é indexada.
            $response->headers->set('Cache-Control', 'private, no-store');
            $response->headers->remove('ETag');
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
            $response->headers->set('X-Site-Preview', 'active'); // o site mostra a faixa da pré-visualização
        }

        return $response;
    }
}
