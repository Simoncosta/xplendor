<?php

use App\Http\Controllers\Api\MarketSnapshotController;
use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Controllers\Api\V1\Admin\SupportTicketController as AdminSupportTicketController;
use App\Http\Controllers\Api\V1\Admin\QuoteController as AdminQuoteController;
use App\Http\Controllers\Api\V1\Admin\StockController as AdminStockController;
use App\Http\Controllers\Api\V1\Admin\CompanyController as AdminCompanyController;
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
    DocumentTemplateController,
    SupportTicketController,
    QuoteController,
    CompanyTaskController,
    CompanyModuleController,
    GoogleAnalyticsController,
    MetaInsightsController,
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
                // DMS Pós-venda — relatório de satisfação (módulo AFTERSALES).
                Route::post('/cars/{carId}/satisfaction-report', [SatisfactionReportController::class, 'store'])->middleware('ensure_module:aftersales');
                Route::get('/cars/{carId}/satisfaction-report/photos', [SatisfactionReportController::class, 'photos'])->middleware('ensure_module:aftersales');
                Route::get('/cars/{carId}/satisfaction-report/review', [SatisfactionReportController::class, 'review'])->middleware('ensure_module:aftersales');
                // DMS Caminho B — gerar documento preenchido (módulo DOCUMENTOS).
                Route::post('/cars/{carId}/document-templates/{templateId}/generate', [DocumentTemplateController::class, 'generate'])->middleware('ensure_module:documents');
                // Envio do link ao cliente (email via queue) + registo de envio (WhatsApp).
                Route::post('/cars/{carId}/satisfaction-report/send-email', [SatisfactionReportController::class, 'sendEmail'])->middleware('ensure_module:aftersales');
                Route::post('/cars/{carId}/satisfaction-report/mark-sent', [SatisfactionReportController::class, 'markSent'])->middleware('ensure_module:aftersales');
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

                // Relatório A — candidatas a promoção (módulo COMERCIAL/CRM).
                Route::middleware('ensure_module:commercial_crm')->group(function () {
                    Route::get('/stock/promotion-candidates', [StockPromotionController::class, 'index']);
                    Route::get('/stock/promotion-candidates/summary', [StockPromotionController::class, 'summary']);
                    Route::post('/stock/promotion-candidates/{carId}', [StockPromotionController::class, 'store']);
                    Route::delete('/stock/promotion-candidates/{carId}', [StockPromotionController::class, 'destroy']);
                });

                Route::post('/scraper/run', [ScraperController::class, 'run']);
                Route::get('/scraper/executions', [ScraperController::class, 'executions']);
                Route::get('/scraper/executions/{runId}', [ScraperController::class, 'show']);

                Route::get('/integrations', [CompanyIntegrationController::class, 'index']);
                Route::post('/integrations/meta/connect', [CompanyIntegrationController::class, 'connectMeta']);
                Route::delete('/integrations/meta', [CompanyIntegrationController::class, 'disconnectMeta']);
                Route::get('/integrations/meta/adsets', [CompanyIntegrationController::class, 'listMetaAdsets']);

                // GA4 — tráfego do site do cliente (Service Account do servidor;
                // property_id por empresa). Scoped por company_id.
                Route::post('/integrations/google/connect', [GoogleAnalyticsController::class, 'connect']);
                Route::delete('/integrations/google', [GoogleAnalyticsController::class, 'disconnect']);
                Route::get('/analytics/ga4/traffic', [GoogleAnalyticsController::class, 'traffic']);

                // Meta — LEITURA dos dados que o pipeline já ingere (gasto/cliques/
                // CTR + vendas atribuídas). Zero fetch/escrita aqui. Scoped por company.
                Route::get('/analytics/meta/overview', [MetaInsightsController::class, 'overview']);

                // Módulos ativos da empresa do utilizador (Fase 2 — esconder secções).
                Route::get('/my-modules', [CompanyModuleController::class, 'active']);

                Route::apiResource('/users', UserController::class);
                // ── Módulo STOCK (Fase 3: recusa 403 se não ativo) ──
                Route::post('/cars/generate-description', [CarController::class, 'generateDescription'])->middleware('ensure_module:stock');
                Route::apiResource('/cars', CarController::class)->middleware('ensure_module:stock');
                // ── Módulo COMERCIAL/CRM ──
                Route::apiResource('/leads', CarLeadController::class)->only(['index', 'update'])->middleware('ensure_module:commercial_crm');
                Route::apiResource('/carmine-connection', CarmineConnectionController::class)->except('index')->middleware('ensure_module:stock');
                Route::apiResource('/blogs', BlogController::class);
                // ── Módulo FINANÇAS ──
                Route::apiResource('/suppliers', SupplierController::class)->middleware('ensure_module:finance');

                // ── Módulo DOCUMENTOS — modelos .docx ──
                Route::middleware('ensure_module:documents')->group(function () {
                    Route::get('/document-templates/variables', [DocumentTemplateController::class, 'variables']);
                    Route::get('/document-templates/example', [DocumentTemplateController::class, 'example']);
                    Route::get('/document-templates', [DocumentTemplateController::class, 'index']);
                    Route::post('/document-templates', [DocumentTemplateController::class, 'store']);
                    Route::post('/document-templates/{template}/replace', [DocumentTemplateController::class, 'replaceFile']);
                    Route::match(['put', 'patch'], '/document-templates/{template}', [DocumentTemplateController::class, 'update']);
                    Route::delete('/document-templates/{template}', [DocumentTemplateController::class, 'destroy']);
                });
                // ── Módulo FINANÇAS — Clientes (+ ficha-hub) ──
                Route::middleware('ensure_module:finance')->group(function () {
                    Route::get('/customers/{customer}/hub', [CustomerController::class, 'hub']);
                    Route::apiResource('/customers', CustomerController::class);
                });

                // DMS — Tickets de suporte (lado STAND). Scoped por empresa.
                Route::get('/support-tickets', [SupportTicketController::class, 'index']);
                Route::post('/support-tickets', [SupportTicketController::class, 'store']);
                Route::get('/support-tickets/{ticket}', [SupportTicketController::class, 'show']);
                Route::post('/support-tickets/{ticket}/messages', [SupportTicketController::class, 'storeMessage']);
                // Site_change (pago): o stand só aprova/rejeita o orçamento.
                Route::patch('/support-tickets/{ticket}/quote-decision', [SupportTicketController::class, 'quoteDecision']);

                // Orçamentos avulsos ligados a esta empresa — ela vê e decide.
                Route::get('/quotes', [QuoteController::class, 'index']);
                Route::patch('/quotes/{quote}/decision', [QuoteController::class, 'decision']);

                // Tarefas internas do cliente (Kanban do stand). Partilhadas por
                // company_id; toda a equipa vê/edita. Colunas fixas todo|doing|done.
                Route::get('/tasks', [CompanyTaskController::class, 'index']);
                Route::post('/tasks', [CompanyTaskController::class, 'store']);
                Route::match(['put', 'patch'], '/tasks/{task}', [CompanyTaskController::class, 'update']);
                Route::patch('/tasks/{task}/move', [CompanyTaskController::class, 'move']);
                Route::delete('/tasks/{task}', [CompanyTaskController::class, 'destroy']);
                // DMS sub-fase 1c.2 — Categorias + Despesas (módulo FINANÇAS).
                Route::middleware('ensure_module:finance')->group(function () {
                    Route::get('/expense-categories/suggested', [ExpenseCategoryController::class, 'suggested']);
                    Route::post('/expense-categories/import-suggested', [ExpenseCategoryController::class, 'importSuggested']);
                    Route::apiResource('/expense-categories', ExpenseCategoryController::class);
                    Route::get('/expenses/summary', [ExpenseController::class, 'summary']);
                    Route::apiResource('/expenses', ExpenseController::class);
                });
                Route::apiResource('/subscribers', NewsletterController::class)->only(['index']);

                Route::post('/car-ai-analyses/{carId}', [CarController::class, 'generateAiAnalyses']);
                Route::put('/car-ai-analyses-feedback/{carAiAnalysesId}', [CarController::class, 'feedbackAiAnalyses']);
                Route::get('cars/{car}/performance', [CarPerformanceMetricController::class, 'index']);
                Route::post('cars/{car}/performance', [CarPerformanceMetricController::class, 'store']);
                Route::get('cars/{car}/performance/summary', [CarPerformanceMetricController::class, 'summary']);
                Route::put('cars/{car}/performance/{metric}', [CarPerformanceMetricController::class, 'update']);
                Route::post('cars/{car}/sales', [CarSaleController::class, 'store']);
                Route::patch('cars/{car}/sale', [CarSaleController::class, 'updateSale']);
                // Fase 2 — CRM: detetar lead aberta do cliente + mover para "Venda".
                Route::get('cars/{car}/sale/lead-match', [CarSaleController::class, 'leadMatch']);
                Route::post('cars/{car}/sale/link-lead', [CarSaleController::class, 'linkLead']);

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

        // ── Consola de Administração da plataforma (super-admin / root) ───────
        // SEM prefixo de empresa: é o ÚNICO grupo com acesso TRANSVERSAL (vê
        // dados de todas as empresas). Portão único: ensure_super_admin. Tudo o
        // que for transversal (tickets, consolas futuras) vive aqui dentro.
        Route::middleware('ensure_super_admin')->prefix('admin')->group(function () {
            Route::get('/ping', [AdminController::class, 'ping']);

            // Tickets de suporte — TRANSVERSAL (todas as empresas).
            Route::get('/tickets/summary', [AdminSupportTicketController::class, 'summary']);
            Route::get('/tickets', [AdminSupportTicketController::class, 'index']);
            Route::get('/tickets/{ticket}', [AdminSupportTicketController::class, 'show']);
            Route::patch('/tickets/{ticket}/status', [AdminSupportTicketController::class, 'updateStatus']);
            Route::patch('/tickets/{ticket}/type', [AdminSupportTicketController::class, 'reclassify']);
            Route::post('/tickets/{ticket}/messages', [AdminSupportTicketController::class, 'storeMessage']);
            // Site_change (pago): orçar, marcar pago (+ fatura PDF), concluir.
            Route::patch('/tickets/{ticket}/quote', [AdminSupportTicketController::class, 'setQuote']);
            Route::post('/tickets/{ticket}/mark-paid', [AdminSupportTicketController::class, 'markPaid']);
            Route::patch('/tickets/{ticket}/complete', [AdminSupportTicketController::class, 'markCompleted']);

            // Orçamentos avulsos — gestão comercial (2ª consola da área /admin).
            Route::get('/quotes/summary', [AdminQuoteController::class, 'summary']);
            Route::get('/quotes/companies', [AdminQuoteController::class, 'companies']);
            Route::get('/quotes', [AdminQuoteController::class, 'index']);
            Route::post('/quotes', [AdminQuoteController::class, 'store']);
            Route::get('/quotes/{quote}', [AdminQuoteController::class, 'show']);
            Route::match(['put', 'patch'], '/quotes/{quote}', [AdminQuoteController::class, 'update']);
            Route::patch('/quotes/{quote}/status', [AdminQuoteController::class, 'updateStatus']);
            Route::patch('/quotes/{quote}/mark-paid', [AdminQuoteController::class, 'markPaid']);
            Route::patch('/quotes/{quote}/complete', [AdminQuoteController::class, 'markCompleted']);
            Route::delete('/quotes/{quote}', [AdminQuoteController::class, 'destroy']);

            // Stock GLOBAL — 1ª vista de dados transversais (veículos de todas
            // as empresas ATIVAS). Só leitura; não toca nos endpoints de stand.
            Route::get('/stock/summary', [AdminStockController::class, 'summary']);
            Route::get('/stock/companies', [AdminStockController::class, 'companies']);
            Route::get('/stock', [AdminStockController::class, 'index']);

            // Ativar/inativar empresa (root). Inativar tira acesso + exclui do stock.
            Route::patch('/companies/{company}/status', [AdminCompanyController::class, 'setStatus']);

            // Módulos por empresa (Incremento 1) — só super-admin.
            Route::get('/companies/{company}/modules', [AdminCompanyController::class, 'modules']);
            Route::patch('/companies/{company}/modules', [AdminCompanyController::class, 'setModule']);
            Route::post('/companies/{company}/modules/preset', [AdminCompanyController::class, 'applyModulePreset']);
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
