<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Access\Access;
use App\Access\CompatibilityProfiles;
use App\Access\Decision;
use App\Access\RoutePermissions;
use App\Access\ShadowLog;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ACL: a permissão de cada rota de empresa (RoutePermissions, ou permission:area.acao na
 * própria rota). Corre depois do tenant. Em modo sombra (config access.mode = shadow)
 * calcula a decisão, deixa o pedido seguir e regista as divergências (ShadowLog); em modo
 * enforce recusa com um único formato de 403: {success: false, message, reason}.
 */
class CheckPermission
{
    public function __construct(private readonly Access $access) {}

    public function handle(Request $request, Closure $next, ?string $permission = null): Response
    {
        $route = $request->route();
        $companyId = $route?->parameter('id') ?? $route?->parameter('company');
        if ($route === null || $companyId === null) {
            return $next($request); // rotas sem empresa no endereço (ex.: GET /companies)
        }

        $key = RoutePermissions::keyFor($route);
        $permission ??= RoutePermissions::permissionFor($route);
        $fullKey = $key === null ? null : CompatibilityProfiles::fullKey($key);

        if ($permission === null) {
            $decision = Decision::deny('Esta rota não tem permissão declarada.', Decision::UNKNOWN);
        } else {
            $middleware = $route->gatherMiddleware();
            $modules = [];
            foreach ($middleware as $m) {
                if (is_string($m) && str_starts_with($m, 'ensure_module:')) {
                    array_push($modules, ...explode(',', substr($m, strlen('ensure_module:'))));
                }
            }
            if (isset(RoutePermissions::MODULES[$key])) {
                $modules[] = RoutePermissions::MODULES[$key]; // D8
            }
            $decision = $this->access->can($request->user(), $companyId, $permission, [
                'route' => $fullKey,
                'modules' => $modules,
                'sensitive' => in_array('block_when_impersonating', $middleware, true) || in_array($key, RoutePermissions::SENSITIVE, true),
                'self' => isset(RoutePermissions::SELF[$key]) && (int) $route->parameter(RoutePermissions::SELF[$key]) === (int) $request->user()?->id,
            ]);
        }
        $request->attributes->set('access_decision', $decision);

        // Depois da F3 as verificações antigas saíram dos controllers: o modo sombra só existe nos testes.
        if (config('access.mode') !== 'shadow' || ! app()->environment('testing')) {
            $response = $decision->denied()
                ? new JsonResponse(['success' => false, 'message' => $decision->reason, 'reason' => $decision->code, 'errors' => null], 403)
                : $next($request);
            if (app()->environment('testing')) {
                ShadowLog::decision((string) $fullKey, $decision->allowed, $response->getStatusCode(), $decision->reason);
            }

            return $response;
        }

        $response = $next($request);
        $status = $response->getStatusCode();
        $type = match (true) {
            $decision->denied() && $status >= 200 && $status < 300 => 'perda',
            $decision->allowed && $status === 403 => 'excesso',
            $decision->denied() && $status !== 403 => 'inconclusivo',
            default => null,
        };
        if ($type !== null) {
            ShadowLog::record($type, (string) $fullKey, (string) $permission, $status, $request->user()?->id, (int) $companyId, $decision->reason);
        }

        return $response;
    }
}
