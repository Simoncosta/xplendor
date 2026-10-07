<?php

namespace App\Http\Middleware;

use App\Helpers\ApiResponse;
use App\Models\Company;
use App\Services\Tenancy\CompanyAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Subscrição avaliada na empresa do ENDEREÇO (/companies/{id}); sem empresa no endereço,
 * na empresa do utilizador. Uma empresa gerida tem o acesso da agência (hasPlatformAccess
 * calculado). Quem trabalha pela agência precisa também da subscrição da própria agência.
 * Sem acesso à empresa do endereço, avalia a do utilizador e deixa o tenant recusar.
 */
class CheckCompanySubscription
{
    public function __construct(private readonly CompanyAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return $next($request);
        }

        if ($user->role === 'root') {
            return $next($request);
        }

        // EXCEÇÃO EXPLÍCITA: uma sessão de impersonation fura o gate de subscrição — é
        // justamente quando a subscrição expira que o root pode precisar de lá entrar.
        // O user NORMAL (sem sessão de impersonation) continua bloqueado.
        if (\App\Models\ImpersonationSession::activeFor($user)) {
            return $next($request);
        }

        $own = $user->company_id ? Company::find($user->company_id) : null;
        if (!$own) {
            return ApiResponse::error(
                'A tua conta não tem uma empresa associada.',
                403
            );
        }

        // Só os endereços /companies/… trazem a empresa ({id} de /districts/{id} não é uma empresa).
        $route = $request->route();
        $routeCompany = $route && str_starts_with($route->uri(), 'api/v1/companies/')
            ? ($request->route('id') ?? $request->route('company'))
            : null;
        $kind = $routeCompany !== null ? $this->access->kind($user, $routeCompany) : null;

        $companies = [$own];
        if ($kind === CompanyAccess::AGENCY) {
            $companies[] = Company::find((int) $routeCompany);
        }

        foreach (array_filter($companies) as $company) {
            if (! $this->hasAccess($company)) {
                return ApiResponse::error(
                    'O periodo experimental expirou. Atualiza o teu plano para continuar a usar a Xplendor.',
                    403,
                    [
                        'subscription_status' => $company->subscription_status,
                        'trial_ends_at' => $company->trial_ends_at?->toISOString(),
                    ]
                );
            }
        }

        return $next($request);
    }

    private function hasAccess(Company $company): bool
    {
        if ($company->isTrialExpired()) {
            $company->update([
                'subscription_status' => Company::SUBSCRIPTION_STATUS_EXPIRED,
                'subscription_ends_at' => $company->trial_ends_at ?? now(),
            ]);

            $company->refresh();
        }

        return $company->hasPlatformAccess();
    }
}
