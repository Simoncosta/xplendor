<?php

use App\Http\Controllers\Api\Public\{
    BlogController as PublicBlogController,
    CarController as PublicCarController,
    CarLeadController as PublicCarLeadController,
    CarViewController,
    NewsletterController as PublicNewsletterController,
    TrackController
};
use App\Http\Controllers\Api\V1\{
    BlogController,
    CarAnalyticsController,
    CarBrandController,
    CarController,
    CarLeadController,
    CarMarketingIdeaController,
    CarmineConnectionController,
    CarModelController,
    CarPerformanceMetricController,
    CarSalePotentialScoreController,
    CompanyController,
    DashboardController,
    DistrictController,
    NewsletterController,
    PlanController,
    UserController
};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/register', [UserController::class, 'store']);
    Route::post('/login', [UserController::class, 'login']);
    Route::post('/register-by-invite', [UserController::class, 'registerByInvite']);
    Route::get('/user-by-invite/{token}', [UserController::class, 'getUserInviteByToken']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [UserController::class, 'logout']);
        Route::post('/revoke-tokens', [UserController::class, 'revokeTokens']);

        Route::apiResource('/plans', PlanController::class);
        Route::apiResource('/companies', CompanyController::class);

        Route::prefix('/companies/{id}')->group(function () {
            Route::post('/blogs/build-rss-url', [BlogController::class, 'buildRssUrl']);
            Route::post('/carmine-connection/sync', [CarmineConnectionController::class, 'sync']);
            Route::get('/cars/{carId}/analytics', [CarAnalyticsController::class, 'show']);
            Route::get('dashboard', [DashboardController::class, 'index']);

            Route::get('/marketing-ideas', [CarMarketingIdeaController::class, 'index']);
            Route::post('/marketing-ideas/generate', [CarMarketingIdeaController::class, 'generate']);

            Route::apiResource('/users', UserController::class);
            Route::apiResource('/cars', CarController::class);
            Route::apiResource('/leads', CarLeadController::class)->only(['index', 'update']);
            Route::apiResource('/carmine-connection', CarmineConnectionController::class)->except('index');
            Route::apiResource('/blogs', BlogController::class);
            Route::apiResource('/subscribers', NewsletterController::class)->only(['index']);

            Route::post('/car-ai-analyses/{carId}', [CarController::class, 'generateAiAnalyses']);
            Route::put('/car-ai-analyses-feedback/{carAiAnalysesId}', [CarController::class, 'feedbackAiAnalyses']);
            Route::get('cars/{car}/performance', [CarPerformanceMetricController::class, 'index']);
            Route::post('cars/{car}/performance', [CarPerformanceMetricController::class, 'store']);
            Route::get('cars/{car}/performance/summary', [CarPerformanceMetricController::class, 'summary']);
            Route::put('cars/{car}/performance/{metric}', [CarPerformanceMetricController::class, 'update']);

            Route::get('cars/{car}/potential-score', [CarSalePotentialScoreController::class, 'show']);
            Route::post('cars/{car}/potential-score/recalculate', [CarSalePotentialScoreController::class, 'recalculate']);
        });

        Route::apiResource('/districts', DistrictController::class)->only(['index']);
        Route::get('/districts/{id}/municipalities', [DistrictController::class, 'getMunicipalities']);
        Route::get('/municipalities/{id}/parishes', [DistrictController::class, 'getParishes']);

        Route::apiResource('/car-brands', CarBrandController::class)->only(['index']);
        Route::apiResource('/car-models', CarModelController::class)->only(['index']);
    });
});

Route::middleware(['check_company_api_token'])->prefix('public')->group(function () {
    Route::get('cars', [PublicCarController::class, 'index']);
    Route::get('cars/{id}', [PublicCarController::class, 'show']);
    Route::get('car-filters', [PublicCarController::class, 'filters']);

    Route::post('car-view', [CarViewController::class, 'store']);
    Route::post('car-lead', [PublicCarLeadController::class, 'store']);
    Route::post('newsletter', [PublicNewsletterController::class, 'store']);

    Route::get('blogs', [PublicBlogController::class, 'index']);
    Route::get('blogs/{slug}', [PublicBlogController::class, 'show']);

    Route::post('track', [TrackController::class, 'store']);
    Route::post('track/carmine', [TrackController::class, 'storeCarmine']);
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::match(['GET', 'OPTIONS'], '/media/{path}', function ($path) {
    $origin = request()->headers->get('Origin');

    $allowed = ['http://localhost:3000']; // adiciona outros se precisares

    if (request()->isMethod('OPTIONS')) {
        return response('', 204, [
            'Access-Control-Allow-Origin' => in_array($origin, $allowed) ? $origin : '',
            'Access-Control-Allow-Methods' => 'GET, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With',
            'Vary' => 'Origin',
        ]);
    }

    $fullPath = storage_path('app/public/' . $path);
    abort_unless(file_exists($fullPath), 404);

    return Response::file($fullPath, [
        'Access-Control-Allow-Origin' => in_array($origin, $allowed) ? $origin : '',
        'Vary' => 'Origin',
    ]);
})->where('path', '.*');
