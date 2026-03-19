<?php

namespace App\Http\Middleware;

use App\Helpers\ApiResponse;
use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckCompanySubscription
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return $next($request);
        }

        if ($user->role === 'root') {
            return $next($request);
        }

        $company = $user->company;

        if (!$company) {
            return ApiResponse::error(
                'A tua conta não tem uma empresa associada.',
                403
            );
        }

        if ($company->isTrialExpired()) {
            $company->update([
                'subscription_status' => Company::SUBSCRIPTION_STATUS_EXPIRED,
                'subscription_ends_at' => $company->trial_ends_at ?? now(),
            ]);

            $company->refresh();
        }

        if ($company->hasPlatformAccess()) {
            return $next($request);
        }

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
