<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckScraperApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedToken = (string) config('services.scraper.token');
        $providedToken = (string) $request->bearerToken();

        if ($expectedToken === '' || $providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
            ], 401);
        }

        return $next($request);
    }
}
