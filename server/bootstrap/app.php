<?php

use App\Http\Middleware\ForceJsonResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(ForceJsonResponse::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // ACL: todos os 403 da API com o mesmo formato {success, message, reason, errors}.
        $exceptions->render(function (\Illuminate\Auth\Access\AuthorizationException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*')) {
                return \App\Helpers\ApiResponse::forbidden('Não tem permissão para esta ação.', 'perfil');
            }
        });
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e, \Illuminate\Http\Request $request) {
            if ($e->getStatusCode() === 403 && $request->is('api/*')) {
                $message = $e->getMessage() !== '' ? $e->getMessage() : 'Não tem permissão para esta ação.';

                return \App\Helpers\ApiResponse::forbidden($message, str_contains($message, 'impersonation') ? \App\Access\Decision::IMPERSONATION : null);
            }
        });
    })->create();
