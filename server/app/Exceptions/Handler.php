<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Throwable;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;

class Handler extends ExceptionHandler
{
    protected $levels = [];

    protected $dontReport = [];

    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function register(): void
    {
        //
    }

    // Padroniza erro de validação em JSON
    protected function invalidJson($request, ValidationException $exception)
    {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors'  => $exception->errors(),
        ], $exception->status);
    }

    // Padroniza qualquer erro de exceção como JSON
    public function render($request, Throwable $e)
    {
        if ($request->is('api/*')) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500);
        }

        return parent::render($request, $e);
    }
}
