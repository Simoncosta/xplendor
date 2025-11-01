<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/register', [\App\Http\Controllers\API\V1\UserController::class, 'store']);
    Route::post('/login', [\App\Http\Controllers\API\V1\UserController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::apiResource('plans', \App\Http\Controllers\Api\V1\PlanController::class);
        Route::apiResource('companies', \App\Http\Controllers\Api\V1\CompanyController::class);
        Route::apiResource('companies.operations', \App\Http\Controllers\Api\V1\CompanyOperationController::class)->except(['index', 'show']);
        Route::apiResource('cars', \App\Http\Controllers\Api\V1\CarController::class);
        Route::apiResource('car-leads', \App\Http\Controllers\Api\V1\CarLeadController::class)->except(['update', 'destroy']);
        Route::apiResource('company-views', \App\Http\Controllers\Api\V1\CompanyViewController::class)->except(['update', 'destroy', 'show']);
        Route::apiResource('car-views', \App\Http\Controllers\Api\V1\CarViewController::class)->except(['update', 'destroy', 'show']);
        Route::apiResource('car-logs', \App\Http\Controllers\Api\V1\CarLogController::class)->except(['update', 'destroy', 'show']);

        Route::post('/logout', [\App\Http\Controllers\API\V1\UserController::class, 'logout']);
        Route::post('/revoke-tokens', [\App\Http\Controllers\API\V1\UserController::class, 'revokeTokens']);
        Route::get('/me', [\App\Http\Controllers\API\V1\UserController::class, 'me']);
        Route::put('/users/{id}', [\App\Http\Controllers\API\V1\UserController::class, 'update']);
    });
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
