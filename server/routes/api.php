<?php

use App\Http\Controllers\Api\V1\{
    CarBrandController,
    CarController,
    CarModelController,
    CompanyController,
    DistrictController,
    PlanController,
    UserController
};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/register', [UserController::class, 'store']);
    Route::post('/login', [UserController::class, 'login']);
    Route::post('/register-by-invite', [UserController::class, 'registerByInvite']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [UserController::class, 'logout']);
        Route::post('/revoke-tokens', [UserController::class, 'revokeTokens']);

        Route::apiResource('/plans', PlanController::class);
        Route::apiResource('/companies', CompanyController::class);

        Route::prefix('/companies/{id}')->group(function () {
            Route::apiResource('/users', UserController::class);
            Route::apiResource('/cars', CarController::class);
        });

        Route::apiResource('/districts', DistrictController::class)->only(['index']);
        Route::get('/districts/{id}/municipalities', [DistrictController::class, 'getMunicipalities']);
        Route::get('/municipalities/{id}/parishes', [DistrictController::class, 'getParishes']);

        Route::apiResource('/car-brands', CarBrandController::class)->only(['index']);
        Route::apiResource('/car-models', CarModelController::class)->only(['index']);
    });
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
