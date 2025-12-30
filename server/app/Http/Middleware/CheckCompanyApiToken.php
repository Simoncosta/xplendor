<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckCompanyApiToken
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->query('token');

        if (!$token || !Company::where('public_api_token', $token)->exists()) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $company = Company::where('public_api_token', $token)->first();

        // Compartilha a empresa para o controller
        $request->merge(['public_api_company' => $company]);


        return $next($request);
    }
}
