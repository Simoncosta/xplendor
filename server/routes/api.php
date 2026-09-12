<?php

use App\Http\Controllers\Api\MarketSnapshotController;
use App\Http\Controllers\Api\Public\{
    BlogController as PublicBlogController,
    CarController as PublicCarController,
    CarLeadController as PublicCarLeadController,
    CarViewController,
    NewsletterController as PublicNewsletterController,
    SatisfactionReportController as PublicSatisfactionReportController,
    TrackController
};
use App\Http\Controllers\Api\V1\{
    ActionExecutionController,
    AlertController,
    BlogController,
    CarAdCampaignController,
    CarAnalyticsController,
    CarBrandController,
    CarCategoryController,
    CarController,
    CarDecisionController,
    CarLeadController,
    CarmineConnectionController,
    CarModelController,
    CarPerformanceMetricController,
    CarSaleController,
    CarSalePotentialScoreController,
    CompanyController,
    CompanyIntegrationController,
    DashboardController,
    DistrictController,
    MetaOAuthController,
    NewsletterController,
    PlanController,
    PromotionRankingController,
    CustomerController,
    SaleDocumentController,
    SatisfactionReportController,
    ExpenseCategoryController,
    ExpenseController,
    ScraperController,
    StockPromotionController,
    SupplierController,
    UserController
};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Route;

Route::middleware(['check_scraper_api_token'])->group(function () {
    Route::post('/market/snapshots', [MarketSnapshotController::class, 'store']);
});

Route::prefix('v1')->group(function () {
    Route::post('/register', [UserController::class, 'store']);
    Route::post('/login', [UserController::class, 'login']);
    Route::post('/register-by-invite', [UserController::class, 'registerByInvite']);
    Route::get('/user-by-invite/{token}', [UserController::class, 'getUserInviteByToken']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [UserController::class, 'logout']);
        Route::post('/revoke-tokens', [UserController::class, 'revokeTokens']);

        Route::apiResource('/plans', PlanController::class);
        Route::post('/companies', [CompanyController::class, 'store']);

        Route::middleware('check_company_subscription')->group(function () {
            Route::apiResource('/companies', CompanyController::class)->except(['store']);

            // Callback OAuth - sem prefixo de company (o company_id vem no state)
            Route::post('integrations/meta/callback', [MetaOAuthController::class, 'handleCallback']);

            Route::prefix('/companies/{id}')->group(function () {
                Route::get('/decisions', [CarDecisionController::class, 'index']);
                Route::get('/alerts', [AlertController::class, 'index']);
                Route::get('/alerts/unread-count', [AlertController::class, 'unreadCount']);
                Route::patch('/alerts/read', [AlertController::class, 'markRead']);
                Route::post('/alerts/{alertId}/read', [AlertController::class, 'markOneRead']);
                Route::post('/blogs/build-rss-url', [BlogController::class, 'buildRssUrl']);
                Route::post('/carmine-connection/sync', [CarmineConnectionController::class, 'sync']);
                Route::get('/cars/{carId}/analytics', [CarAnalyticsController::class, 'show']);
                Route::get('/cars/{carId}/specs', [CarController::class, 'specs']);
                // DMS Fase 2A — margem simples da viatura vendida.
                Route::get('/cars/{carId}/margin', [CarController::class, 'margin']);
                // DMS Fase 3 — dados para os documentos de venda (empresa + viatura + cliente).
                Route::get('/cars/{carId}/sale-document-data', [SaleDocumentController::class, 'data']);
                // DMS Pós-venda — cria (ou reaproveita) o relatório de satisfação de uma venda.
                Route::post('/cars/{carId}/satisfaction-report', [SatisfactionReportController::class, 'store']);
                // Fotos que o cliente carregou no relatório (o stand vê na Ficha).
                Route::get('/cars/{carId}/satisfaction-report/photos', [SatisfactionReportController::class, 'photos']);
                Route::get('/cars/{carId}/satisfaction-report/review', [SatisfactionReportController::class, 'review']);
                // Envio do link ao cliente (email via queue) + registo de envio (WhatsApp).
                Route::post('/cars/{carId}/satisfaction-report/send-email', [SatisfactionReportController::class, 'sendEmail']);
                Route::post('/cars/{carId}/satisfaction-report/mark-sent', [SatisfactionReportController::class, 'markSent']);
                // Ficha de impressão A4 (2026-06-27) — payload dedicado.
                Route::get('/cars/{carId}/print-sheet', [CarController::class, 'printSheet']);
                Route::get('/cars/{carId}/decision', [CarDecisionController::class, 'show']);
                Route::get('/cars/promotion-ranking', [PromotionRankingController::class, 'index']);
                Route::post('/cars/{carId}/execute-action', [ActionExecutionController::class, 'store']);
                Route::get('/cars/{carId}/audience', [CarController::class, 'audience']);
                Route::get('/cars/{carId}/audience-analysis', [CarController::class, 'audienceAnalysis']);
                Route::get('/cars/{carId}/images/download', [CarController::class, 'downloadImages']);
                Route::post('/cars/{carId}/images/{imageId}/recrop', [CarController::class, 'recropImage']);

                Route::get('/cars/{carId}/ad-campaigns', [CarAdCampaignController::class, 'index']);
                Route::get('/cars/{carId}/ad-campaigns/active-targets', [CarAdCampaignController::class, 'activeTargets']);
                Route::post('/cars/{carId}/ad-campaigns', [CarAdCampaignController::class, 'store']);
                Route::delete('/cars/{carId}/ad-campaigns/{campaign}', [CarAdCampaignController::class, 'destroy']);
                Route::patch('/cars/{carId}/ad-campaigns/{campaign}/toggle', [CarAdCampaignController::class, 'toggle']);

                Route::get('dashboard', [DashboardController::class, 'index']);
                // Visões 1+2 do Dashboard (2026-06-25) — stock por marca + tipo.
                // Endpoint separado para não inflar o blob do index (dívida 9).
                Route::get('dashboard/stock-breakdown', [DashboardController::class, 'stockBreakdown']);
                // Visão 3 do Dashboard (2026-06-25) — FATURAÇÃO por período
                // (NÃO É LUCRO). ?from=Y-m-d&to=Y-m-d&granularity=month|year.
                Route::get('dashboard/sales-revenue', [DashboardController::class, 'salesRevenue']);

                Route::post('/cars/{carId}/meta-ads/refresh', [CarController::class, 'refreshMetaAds']);
                Route::post('/cars/{carId}/analysis/regenerate', [CarController::class, 'regenerateAiAnalysis']);
                Route::get('/cars/{carId}/market-aggregate', [CarController::class, 'marketAggregate']);
                Route::post('/cars/{carId}/market-aggregate/refresh', [CarController::class, 'refreshMarketAggregate']);
                Route::get('/cars/{carId}/market-aggregate/check-link', [CarController::class, 'checkMarketLink']);

                // Relatório A — candidatas a promoção (Camada 1: flag manual).
                // Camada 2 (orçamento + Meta) entra depois.
                Route::get('/stock/promotion-candidates', [StockPromotionController::class, 'index']);
                Route::get('/stock/promotion-candidates/summary', [StockPromotionController::class, 'summary']);
                Route::post('/stock/promotion-candidates/{carId}', [StockPromotionController::class, 'store']);
                Route::delete('/stock/promotion-candidates/{carId}', [StockPromotionController::class, 'destroy']);

                Route::post('/scraper/run', [ScraperController::class, 'run']);
                Route::get('/scraper/executions', [ScraperController::class, 'executions']);
                Route::get('/scraper/executions/{runId}', [ScraperController::class, 'show']);

                Route::get('/integrations', [CompanyIntegrationController::class, 'index']);
                Route::post('/integrations/meta/connect', [CompanyIntegrationController::class, 'connectMeta']);
                Route::delete('/integrations/meta', [CompanyIntegrationController::class, 'disconnectMeta']);
                Route::get('/integrations/meta/adsets', [CompanyIntegrationController::class, 'listMetaAdsets']);

                Route::apiResource('/users', UserController::class);
                Route::post('/cars/generate-description', [CarController::class, 'generateDescription']);
                Route::apiResource('/cars', CarController::class);
                Route::apiResource('/leads', CarLeadController::class)->only(['index', 'update']);
                Route::apiResource('/carmine-connection', CarmineConnectionController::class)->except('index');
                Route::apiResource('/blogs', BlogController::class);
                // DMS sub-fase 1c.1 — Fornecedores (base para despesas).
                Route::apiResource('/suppliers', SupplierController::class);
                // DMS — Clientes (base para documentos de venda, Fase 3).
                Route::apiResource('/customers', CustomerController::class);
                // DMS sub-fase 1c.2a — Categorias de despesa (pré-requisito das despesas).
                Route::get('/expense-categories/suggested', [ExpenseCategoryController::class, 'suggested']);
                Route::post('/expense-categories/import-suggested', [ExpenseCategoryController::class, 'importSuggested']);
                Route::apiResource('/expense-categories', ExpenseCategoryController::class);
                // DMS sub-fase 1c.2b — Despesas.
                Route::get('/expenses/summary', [ExpenseController::class, 'summary']);
                Route::apiResource('/expenses', ExpenseController::class);
                Route::apiResource('/subscribers', NewsletterController::class)->only(['index']);

                Route::post('/car-ai-analyses/{carId}', [CarController::class, 'generateAiAnalyses']);
                Route::put('/car-ai-analyses-feedback/{carAiAnalysesId}', [CarController::class, 'feedbackAiAnalyses']);
                Route::get('cars/{car}/performance', [CarPerformanceMetricController::class, 'index']);
                Route::post('cars/{car}/performance', [CarPerformanceMetricController::class, 'store']);
                Route::get('cars/{car}/performance/summary', [CarPerformanceMetricController::class, 'summary']);
                Route::put('cars/{car}/performance/{metric}', [CarPerformanceMetricController::class, 'update']);
                Route::post('cars/{car}/sales', [CarSaleController::class, 'store']);
                Route::patch('cars/{car}/sale', [CarSaleController::class, 'updateSale']);

                Route::get('cars/{car}/potential-score', [CarSalePotentialScoreController::class, 'show']);
                Route::post('cars/{car}/potential-score/recalculate', [CarSalePotentialScoreController::class, 'recalculate']);

                // OAuth Meta
                Route::get('integrations/meta/oauth-url', [MetaOAuthController::class, 'getAuthUrl']);
                Route::get('integrations', [CompanyIntegrationController::class, 'index']);
                Route::delete('integrations/meta', [CompanyIntegrationController::class, 'disconnectMeta']);
                Route::get('integrations/meta/adsets', [CompanyIntegrationController::class, 'listMetaAdsets']);
            });

            Route::apiResource('/districts', DistrictController::class)->only(['index']);
            Route::get('/districts/{id}/municipalities', [DistrictController::class, 'getMunicipalities']);
            Route::get('/municipalities/{id}/parishes', [DistrictController::class, 'getParishes']);

            Route::apiResource('/car-categories', CarCategoryController::class)->only(['index']);
            Route::apiResource('/car-brands', CarBrandController::class)->only(['index']);
            Route::apiResource('/car-models', CarModelController::class)->only(['index']);
        });
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

// DMS Pós-venda — relatório público de satisfação. Grupo SEPARADO do
// check_company_api_token: a chave é o token individual do relatório (não o da
// empresa). Throttle por rota protege contra abuso (upload por terceiro sem login).
Route::middleware(['resolve_report_token'])->prefix('public')->group(function () {
    Route::get('report/{token}', [PublicSatisfactionReportController::class, 'show'])
        ->middleware('throttle:60,1');
    Route::post('report/{token}/rating', [PublicSatisfactionReportController::class, 'storeRating'])
        ->middleware('throttle:15,1');
    Route::post('report/{token}/photos', [PublicSatisfactionReportController::class, 'storePhoto'])
        ->middleware('throttle:10,1');
    Route::delete('report/{token}/photos/{photo}', [PublicSatisfactionReportController::class, 'destroyPhoto'])
        ->middleware('throttle:20,1');
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
