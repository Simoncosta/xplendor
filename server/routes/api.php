<?php

use App\Http\Controllers\Api\MarketSnapshotController;
use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Controllers\Api\V1\CollaboratorController;
use App\Http\Controllers\Api\V1\CompanyDepartmentController;
use App\Http\Controllers\Api\Public\TeamController as PublicTeamController;
use App\Http\Controllers\Api\V1\Admin\SupportTicketController as AdminSupportTicketController;
use App\Http\Controllers\Api\V1\Admin\QuoteController as AdminQuoteController;
use App\Http\Controllers\Api\V1\Admin\ServiceCatalogController as AdminServiceCatalogController;
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
    CompanyPingwinController,
    CompanyInvoiceOcrController,
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

// OAuth Meta — callback do browser (GET, PÚBLICO). O Meta redireciona o browser
// para aqui com ?code&state; não traz o bearer da SPA, por isso NÃO pode estar
// atrás do auth:sanctum. O company_id vem do state (nonce validado em cache) e o
// handler troca o code pelo token (secret no backend) e REDIRECIONA para /app.
// URL completo: /api/oauth/meta/callback (registar este em "Valid OAuth Redirect
// URIs" na Meta). Fica fora do grupo v1 para dar exactamente este caminho.
Route::get('/oauth/meta/callback', [MetaOAuthController::class, 'handleCallbackRedirect']);
// Redes sociais (Instagram e Facebook): callback próprio, separado do dos anúncios.
// URL completo: /api/oauth/meta/social/callback (registar também em "Valid OAuth Redirect URIs").
Route::get('/oauth/meta/social/callback', [\App\Http\Controllers\Api\V1\SocialConnectionController::class, 'callback']);

Route::prefix('v1')->group(function () {
    Route::post('/register', [UserController::class, 'store']);
    Route::post('/login', [UserController::class, 'login']);
    Route::post('/register-by-invite', [UserController::class, 'registerByInvite']);
    Route::get('/user-by-invite/{token}', [UserController::class, 'getUserInviteByToken']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [UserController::class, 'logout']);
        Route::post('/revoke-tokens', [UserController::class, 'revokeTokens'])->middleware('block_when_impersonating');

        // IMPERSONATION — terminar + estado atual (verdade do backend p/ o banner). Fora do
        // check_company_subscription: "sair" tem de funcionar mesmo com subscrição expirada.
        Route::post('/impersonation/stop', [\App\Http\Controllers\Api\V1\ImpersonationController::class, 'stop']);
        Route::get('/impersonation/current', [\App\Http\Controllers\Api\V1\ImpersonationController::class, 'current']);

        Route::apiResource('/plans', PlanController::class);
        Route::post('/companies', [CompanyController::class, 'store']);

        Route::middleware('check_company_subscription')->group(function () {
            // tenant: o {company} tem de ser a empresa do utilizador (ou root).
            Route::apiResource('/companies', CompanyController::class)->except(['store'])->middleware(['tenant', 'permission']);

            // Callback OAuth (legado) - sem prefixo de company: a empresa vem do
            // nonce em cache (state), validado contra a empresa do utilizador.
            Route::post('integrations/meta/callback', [MetaOAuthController::class, 'handleCallback']);

            // tenant: portão único — o {id} tem de ser a empresa do utilizador (ou
            // root). Corre antes do ensure_module das rotas de dentro do grupo.
            // permission: a permissão de cada rota (app/Access/RoutePermissions.php), depois do tenant.
            Route::prefix('/companies/{id}')->middleware(['tenant', 'permission'])->group(function () {
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
                // Hub do Automóvel: resumo + recomendações + avisos; funil por viatura.
                Route::get('automotive-hub', [\App\Http\Controllers\Api\V1\AutomotiveHubController::class, 'index']);
                Route::get('automotive-hub/funnel', [\App\Http\Controllers\Api\V1\AutomotiveHubController::class, 'funnel']);
                // Dashboard do automóvel: bloco "Marketing e resultados" (GA4 + Meta + leads).
                Route::get('analytics/automotive/marketing', [\App\Http\Controllers\Api\V1\AutomotiveMarketingController::class, 'show'])
                    ->middleware('ensure_module:stock');

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
                // Credenciais de integrações — SENSÍVEL: bloqueado em impersonation.
                Route::post('/integrations/meta/connect', [CompanyIntegrationController::class, 'connectMeta'])->middleware('block_when_impersonating');
                Route::delete('/integrations/meta', [CompanyIntegrationController::class, 'disconnectMeta'])->middleware('block_when_impersonating');
                Route::get('/integrations/meta/adsets', [CompanyIntegrationController::class, 'listMetaAdsets']);

                // Redes sociais (Instagram e Facebook): ligação separada da dos anúncios. Ver:
                // qualquer utilizador; ligar, escolher e desligar: admin da empresa, nunca em impersonation.
                Route::get('/integrations/social', [\App\Http\Controllers\Api\V1\SocialConnectionController::class, 'show']);
                Route::middleware('block_when_impersonating')->group(function () {
                    Route::get('/integrations/social/auth-url', [\App\Http\Controllers\Api\V1\SocialConnectionController::class, 'authUrl']);
                    Route::get('/integrations/social/candidates', [\App\Http\Controllers\Api\V1\SocialConnectionController::class, 'candidates']);
                    Route::put('/integrations/social/accounts', [\App\Http\Controllers\Api\V1\SocialConnectionController::class, 'saveAccounts']);
                    Route::delete('/integrations/social', [\App\Http\Controllers\Api\V1\SocialConnectionController::class, 'disconnect']);
                });

                // F1c: link de configuração do cliente (gerar, renovar, revogar: admin da empresa,
                // root ou admin da agência gestora, nunca em impersonation) e histórico das ligações.
                Route::get('/integrations/history', [\App\Http\Controllers\Api\V1\CompanySetupLinkController::class, 'history']);
                Route::middleware('block_when_impersonating')->group(function () {
                    Route::get('/setup-link', [\App\Http\Controllers\Api\V1\CompanySetupLinkController::class, 'show']);
                    Route::post('/setup-links', [\App\Http\Controllers\Api\V1\CompanySetupLinkController::class, 'store'])->middleware('throttle:20,1');
                    Route::post('/setup-links/{linkId}/extend', [\App\Http\Controllers\Api\V1\CompanySetupLinkController::class, 'extend'])->whereNumber('linkId');
                    Route::post('/setup-links/{linkId}/revoke', [\App\Http\Controllers\Api\V1\CompanySetupLinkController::class, 'revoke'])->whereNumber('linkId');
                    // Contas de anúncios da autorização atual (escolher numa lista em vez de escrever o ID).
                    Route::get('/integrations/meta/ad-accounts', [CompanyIntegrationController::class, 'metaAdAccounts']);
                });

                // GA4 — tráfego do site do cliente (Service Account do servidor;
                // property_id por empresa). Scoped por company_id.
                Route::post('/integrations/google/connect', [GoogleAnalyticsController::class, 'connect'])->middleware('block_when_impersonating');
                Route::delete('/integrations/google', [GoogleAnalyticsController::class, 'disconnect'])->middleware('block_when_impersonating');
                Route::get('/analytics/ga4/traffic', [GoogleAnalyticsController::class, 'traffic']);

                // Meta — LEITURA dos dados que o pipeline já ingere (gasto/cliques/
                // CTR + vendas atribuídas). Zero fetch/escrita aqui. Scoped por company.
                Route::get('/analytics/meta/overview', [MetaInsightsController::class, 'overview']);
                // Avisos de qualidade da tag [id:N] nos anúncios (tags inválidas).
                Route::get('/analytics/meta/ad-tag-warnings', [MetaInsightsController::class, 'adTagWarnings']);

                // Módulos ativos da empresa do utilizador (Fase 2 — esconder secções).
                Route::get('/my-modules', [CompanyModuleController::class, 'active']);
                Route::get('/my-access', [\App\Http\Controllers\Api\V1\AccessController::class, 'show']); // ACL (F4)
                // ACL (F5): Utilizadores › Perfis e o teto da agência gestora.
                Route::get('/permission-profiles', [\App\Http\Controllers\Api\V1\PermissionProfileController::class, 'index']);
                Route::post('/permission-profiles', [\App\Http\Controllers\Api\V1\PermissionProfileController::class, 'store'])->middleware('block_when_impersonating');
                Route::post('/permission-profiles/preview', [\App\Http\Controllers\Api\V1\PermissionProfileController::class, 'preview']);
                Route::put('/permission-profiles/{profileId}', [\App\Http\Controllers\Api\V1\PermissionProfileController::class, 'update'])->whereNumber('profileId')->middleware('block_when_impersonating');
                Route::delete('/permission-profiles/{profileId}', [\App\Http\Controllers\Api\V1\PermissionProfileController::class, 'destroy'])->whereNumber('profileId')->middleware('block_when_impersonating');
                Route::put('/users/{user}/profile', [\App\Http\Controllers\Api\V1\PermissionProfileController::class, 'assign'])->whereNumber('user')->middleware('block_when_impersonating');
                Route::put('/management/guest-profile', [\App\Http\Controllers\Api\V1\PermissionProfileController::class, 'setCeiling'])->middleware('block_when_impersonating');

                // PingWin (POS restauração) — gate pelo módulo 'pingwin'. A senha
                // é cifrada; o cliente Python garante o logout.
                Route::middleware('ensure_module:pingwin')->group(function () {
                    Route::get('/integrations/pingwin', [CompanyPingwinController::class, 'index']);
                    Route::post('/integrations/pingwin/connect', [CompanyPingwinController::class, 'connect'])->middleware('block_when_impersonating');
                    Route::post('/integrations/pingwin/sync', [CompanyPingwinController::class, 'sync']);
                    // Gatilho do dashboard: sincronização em FILA (não trava; notifica no sino).
                    Route::post('/integrations/pingwin/sync-queue', [CompanyPingwinController::class, 'queueSync']);
                    // Dashboard de restauração (cards anual/mensal/diário + lojas).
                    Route::get('/analytics/pingwin/dashboard', [CompanyPingwinController::class, 'dashboard']);
                    // Calendário de faturação (números por dia do mês + filtro por loja).
                    Route::get('/analytics/pingwin/calendar', [CompanyPingwinController::class, 'calendar']);
                    // Faturação mensal por loja (uma linha por loja, filtro de ano).
                    Route::get('/analytics/pingwin/monthly-billing', [CompanyPingwinController::class, 'monthlyBilling']);
                    // Dashboard de restauração — bloco "marketing e resultados" (GA4 +
                    // Meta + dados internos) de um mês: ?month=Y-m (default: mês em curso).
                    Route::get('/analytics/restaurant/marketing', [\App\Http\Controllers\Api\V1\RestaurantMarketingController::class, 'show']);
                    // Documentos PingWin (Fase 1, só leitura): lista + sincronizar.
                    Route::get('/integrations/pingwin/documents', [CompanyPingwinController::class, 'documents']);
                    Route::post('/integrations/pingwin/documents/sync', [CompanyPingwinController::class, 'syncDocuments']);
                    // Documentos — LEITURA RICA (Fase D0): sincronizar config completa + detalhe por id.
                    Route::post('/integrations/pingwin/documents/sync-rich', [CompanyPingwinController::class, 'syncDocumentsRich']);
                    // D3 (ESCRITA): criar documento novo.
                    Route::post('/integrations/pingwin/documents', [CompanyPingwinController::class, 'createDocumentConfig']);
                    // D1 (ESCRITA): editar maindataset de um documento ativo + polling do estado.
                    Route::get('/integrations/pingwin/documents/writes/{writeId}', [CompanyPingwinController::class, 'documentConfigWrite'])->whereNumber('writeId');
                    Route::match(['put', 'patch'], '/integrations/pingwin/documents/{externalId}', [CompanyPingwinController::class, 'updateDocumentConfig'])->whereNumber('externalId');
                    // D4 (ESCRITA): anular (soft-delete) documento ativo. Destrutiva → bloqueada em impersonation.
                    Route::delete('/integrations/pingwin/documents/{externalId}', [CompanyPingwinController::class, 'voidDocumentConfig'])->whereNumber('externalId')->middleware('block_when_impersonating');
                    Route::get('/integrations/pingwin/documents/{externalId}', [CompanyPingwinController::class, 'documentConfigDetail'])->whereNumber('externalId');
                    // Artigos PingWin (Fase 1, só leitura): lista paginada + sincronizar.
                    Route::get('/integrations/pingwin/catalog', [CompanyPingwinController::class, 'catalog']);
                    Route::post('/integrations/pingwin/catalog/sync', [CompanyPingwinController::class, 'syncCatalog']);
                    // Artigos — form de criação (SÓ LEITURA: next code + lookups do servidor).
                    Route::get('/integrations/pingwin/articles/form-lookups', [CompanyPingwinController::class, 'articleFormLookups']);
                    // Artigos — LER/abrir pelo id do PingWin (leitura autoritativa; numérico
                    // p/ não colidir com articles/form-lookups nem creation/deletion).
                    Route::get('/integrations/pingwin/articles/{productId}', [CompanyPingwinController::class, 'showArticle'])->whereNumber('productId');
                    // Artigos — poll do resultado de uma leitura assíncrona (form-lookups/artigo).
                    Route::get('/integrations/pingwin/articles/read/{token}', [CompanyPingwinController::class, 'articleRead']);
                    // Artigos — tab Compras (C1, só leitura): linhas de fornecedor do espelho.
                    Route::get('/integrations/pingwin/articles/{catalogItemId}/supplier-prices', [CompanyPingwinController::class, 'supplierPrices'])->whereNumber('catalogItemId');
                    // Artigos — CRIAR (escrita; exige confirm) + poll do resultado.
                    Route::post('/integrations/pingwin/articles', [CompanyPingwinController::class, 'createArticle']);
                    Route::get('/integrations/pingwin/articles/creation/{creationId}', [CompanyPingwinController::class, 'articleCreation']);
                    // Artigos — EDITAR (escrita; exige confirm) + poll do resultado.
                    Route::match(['put', 'patch'], '/integrations/pingwin/articles/{catalogItemId}', [CompanyPingwinController::class, 'updateArticle'])->whereNumber('catalogItemId');
                    Route::get('/integrations/pingwin/articles/edition/{creationId}', [CompanyPingwinController::class, 'articleUpdate']);
                    // Artigos — ANULAR (DELETE definitivo; exige confirm) + poll do resultado.
                    // ANULAR artigo (DELETE definitivo) — SENSÍVEL: bloqueado em impersonation.
                    Route::delete('/integrations/pingwin/articles/{catalogItemId}', [CompanyPingwinController::class, 'deleteArticle'])->middleware('block_when_impersonating');
                    Route::get('/integrations/pingwin/articles/deletion/{creationId}', [CompanyPingwinController::class, 'articleDeletion']);
                    // Famílias PingWin (Fase 1, só leitura): árvore + sincronizar (+ religa artigos).
                    Route::get('/integrations/pingwin/families', [CompanyPingwinController::class, 'families']);
                    Route::post('/integrations/pingwin/families/sync', [CompanyPingwinController::class, 'syncFamilies']);
                    // Fornecedores PingWin (Fase 1, só leitura): lista paginada + sincronizar.
                    Route::get('/integrations/pingwin/suppliers', [CompanyPingwinController::class, 'suppliers']);
                    Route::post('/integrations/pingwin/suppliers/sync', [CompanyPingwinController::class, 'syncSuppliers']);
                    // FN — ⚠️ ESCRITA de fornecedores no PingWin (criar / editar / anular) + polling da escrita.
                    Route::middleware('ensure_module:restauracao_fornecedores')->group(function () {
                        Route::post('/integrations/pingwin/suppliers', [\App\Http\Controllers\Api\V1\CompanyPingwinSupplierWriteController::class, 'store']);
                        Route::get('/integrations/pingwin/suppliers/writes/{writeId}', [\App\Http\Controllers\Api\V1\CompanyPingwinSupplierWriteController::class, 'write'])->whereNumber('writeId');
                        Route::match(['put', 'patch'], '/integrations/pingwin/suppliers/{supplierId}', [\App\Http\Controllers\Api\V1\CompanyPingwinSupplierWriteController::class, 'update'])->whereNumber('supplierId');
                        Route::delete('/integrations/pingwin/suppliers/{supplierId}', [\App\Http\Controllers\Api\V1\CompanyPingwinSupplierWriteController::class, 'destroy'])->whereNumber('supplierId')->middleware('block_when_impersonating');
                    });
                    // Condições de Pagamento PingWin (Fatia 1, só leitura): lista paginada + sincronizar.
                    Route::get('/integrations/pingwin/payment-conditions', [CompanyPingwinController::class, 'paymentConditions']);
                    Route::post('/integrations/pingwin/payment-conditions/sync', [CompanyPingwinController::class, 'syncPaymentConditions']);
                    // Condições de Pagamento PingWin (Fatia 2a, ESCRITA): template de documentos + criar + polling.
                    Route::get('/integrations/pingwin/payment-conditions/docs-template', [CompanyPingwinController::class, 'paymentConditionDocsTemplate']);
                    Route::get('/integrations/pingwin/payment-conditions/creation/{creationId}', [CompanyPingwinController::class, 'paymentConditionCreation'])->whereNumber('creationId');
                    Route::post('/integrations/pingwin/payment-conditions', [CompanyPingwinController::class, 'createPaymentCondition']);
                    // Fatia 2b (ESCRITA): editar condição ATIVA por id (pingwin_id). code read-only.
                    Route::match(['put', 'patch'], '/integrations/pingwin/payment-conditions/{paycondId}', [CompanyPingwinController::class, 'updatePaymentCondition'])->whereNumber('paycondId');
                    // Fatia 2c (ESCRITA): anular (soft-delete) condição ATIVA por id. Destrutiva → bloqueada em impersonation.
                    Route::delete('/integrations/pingwin/payment-conditions/{paycondId}', [CompanyPingwinController::class, 'voidPaymentCondition'])->whereNumber('paycondId')->middleware('block_when_impersonating');
                    // Unidades PingWin: lista paginada + sincronizar (porta 8138).
                    Route::get('/integrations/pingwin/units', [CompanyPingwinController::class, 'units']);
                    Route::post('/integrations/pingwin/units/sync', [CompanyPingwinController::class, 'syncUnits']);
                    // ⚠️ ESCRITA: criar/editar/anular unidade (Action NEW / EDIT,SAVE, porta 8136) — confirm obrigatório.
                    Route::post('/integrations/pingwin/units/create', [CompanyPingwinController::class, 'createUnit']);
                    Route::get('/integrations/pingwin/units/creations/{creationId}', [CompanyPingwinController::class, 'unitCreation']);
                    Route::get('/integrations/pingwin/units/{unitId}/usage', [CompanyPingwinController::class, 'unitUsage']);
                    Route::post('/integrations/pingwin/units/{unitId}/edit', [CompanyPingwinController::class, 'editUnit']);
                    Route::post('/integrations/pingwin/units/{unitId}/anular', [CompanyPingwinController::class, 'anularUnit']);
                    // OCR de faturas de fornecedor (Fase A): carregar → IA lê → validar → guardar (SEM PingWin).
                    Route::get('/ocr/invoices', [CompanyInvoiceOcrController::class, 'index']);
                    Route::post('/ocr/invoices', [CompanyInvoiceOcrController::class, 'upload']);
                    Route::get('/ocr/invoices/{invoiceId}', [CompanyInvoiceOcrController::class, 'show']);
                    Route::get('/ocr/invoices/{invoiceId}/image', [CompanyInvoiceOcrController::class, 'image']);
                    Route::put('/ocr/invoices/{invoiceId}', [CompanyInvoiceOcrController::class, 'update']);
                    Route::post('/ocr/invoices/{invoiceId}/reprocess', [CompanyInvoiceOcrController::class, 'reprocess']);
                    // F3: ligação Fatura OCR ↔ documento(s) do PingWin (só espelhos; nunca escreve no PingWin)
                    Route::post('/ocr/invoices/{invoiceId}/pingwin-link', [CompanyInvoiceOcrController::class, 'pingwinSearch']);
                    Route::post('/ocr/invoices/{invoiceId}/pingwin-link/confirm', [CompanyInvoiceOcrController::class, 'pingwinConfirm']);
                    Route::delete('/ocr/invoices/{invoiceId}/pingwin-link', [CompanyInvoiceOcrController::class, 'pingwinUnlink']);
                    // F2b: artigos nas linhas (associar/criar; escritas no PingWin assíncronas)
                    Route::get('/ocr/articles/search', [\App\Http\Controllers\Api\V1\CompanyOcrLineArticleController::class, 'search']);
                    Route::get('/ocr/articles/form', [\App\Http\Controllers\Api\V1\CompanyOcrLineArticleController::class, 'form']);
                    Route::post('/ocr/invoices/{invoiceId}/lines/accept-suggestions', [\App\Http\Controllers\Api\V1\CompanyOcrLineArticleController::class, 'acceptSuggestions']);
                    Route::post('/ocr/invoices/{invoiceId}/lines/relink', [\App\Http\Controllers\Api\V1\CompanyOcrLineArticleController::class, 'relink']);
                    Route::post('/ocr/invoices/{invoiceId}/lines/{lineId}/article', [\App\Http\Controllers\Api\V1\CompanyOcrLineArticleController::class, 'associate'])->whereNumber('lineId');
                    Route::delete('/ocr/invoices/{invoiceId}/lines/{lineId}/article', [\App\Http\Controllers\Api\V1\CompanyOcrLineArticleController::class, 'unlink'])->whereNumber('lineId');
                    Route::post('/ocr/invoices/{invoiceId}/lines/{lineId}/create-article', [\App\Http\Controllers\Api\V1\CompanyOcrLineArticleController::class, 'createArticle'])->whereNumber('lineId');
                    // FB-1: "Lançar no PingWin" (rascunho 8001 → fechar 8002 / anular 8003); escritas assíncronas
                    Route::get('/ocr/invoices/{invoiceId}/pingwin-launch', [\App\Http\Controllers\Api\V1\CompanyOcrLaunchController::class, 'show']);
                    Route::post('/ocr/invoices/{invoiceId}/pingwin-launch', [\App\Http\Controllers\Api\V1\CompanyOcrLaunchController::class, 'store'])->middleware('block_when_impersonating');
                    Route::post('/ocr/invoices/{invoiceId}/pingwin-launch/close', [\App\Http\Controllers\Api\V1\CompanyOcrLaunchController::class, 'close'])->middleware('block_when_impersonating');
                    Route::post('/ocr/invoices/{invoiceId}/pingwin-launch/void', [\App\Http\Controllers\Api\V1\CompanyOcrLaunchController::class, 'void'])->middleware('block_when_impersonating');
                    Route::post('/ocr/invoices/{invoiceId}/lines/{lineId}/launch-unit', [\App\Http\Controllers\Api\V1\CompanyOcrLaunchController::class, 'setLineUnit'])->whereNumber('lineId');
                    // F1 — documentos de fornecedor do PingWin (SÓ LEITURA): lista, sync por período e estado do run.
                    Route::get('/integrations/pingwin/supplier-documents', [\App\Http\Controllers\Api\V1\CompanySupplierDocumentsController::class, 'index']);
                    Route::post('/integrations/pingwin/supplier-documents/sync', [\App\Http\Controllers\Api\V1\CompanySupplierDocumentsController::class, 'sync']);
                    Route::get('/integrations/pingwin/supplier-documents/sync-runs/{runId}', [\App\Http\Controllers\Api\V1\CompanySupplierDocumentsController::class, 'run'])->whereNumber('runId');
                    // S2 — Conta corrente de fornecedor (SÓ LEITURA no PingWin): visão geral, fornecedor, extrato, atualizar.
                    Route::middleware('ensure_module:restauracao_conta_corrente')->group(function () {
                        Route::get('/integrations/pingwin/supplier-cc', [\App\Http\Controllers\Api\V1\CompanySupplierCcController::class, 'index']);
                        Route::get('/integrations/pingwin/supplier-cc/{supplierId}', [\App\Http\Controllers\Api\V1\CompanySupplierCcController::class, 'show'])->whereNumber('supplierId');
                        Route::get('/integrations/pingwin/supplier-cc/{supplierId}/statement', [\App\Http\Controllers\Api\V1\CompanySupplierCcController::class, 'statement'])->whereNumber('supplierId');
                        Route::post('/integrations/pingwin/supplier-cc/{supplierId}/refresh', [\App\Http\Controllers\Api\V1\CompanySupplierCcController::class, 'refresh'])->whereNumber('supplierId');
                        Route::get('/integrations/pingwin/supplier-cc/{supplierId}/status', [\App\Http\Controllers\Api\V1\CompanySupplierCcController::class, 'status'])->whereNumber('supplierId');
                    });
                    // F1-3 do marketing da restauração: dados para o marketing e categorias das famílias.
                    Route::get('/integrations/pingwin/marketing-data', [\App\Http\Controllers\Api\V1\RestaurantMarketingDataController::class, 'show']);
                    Route::get('/integrations/pingwin/heatmap', [\App\Http\Controllers\Api\V1\RestaurantMarketingDataController::class, 'heatmap']);
                    // F3: "O que publicar e quando" (sinais, ignorar, criar publicação).
                    Route::get('/integrations/pingwin/signals', [\App\Http\Controllers\Api\V1\RestaurantSignalsController::class, 'index']);
                    Route::post('/integrations/pingwin/signals/ignore', [\App\Http\Controllers\Api\V1\RestaurantSignalsController::class, 'ignore']);
                    Route::post('/integrations/pingwin/signals/restore', [\App\Http\Controllers\Api\V1\RestaurantSignalsController::class, 'restore']);
                    Route::post('/integrations/pingwin/signals/exclude-item', [\App\Http\Controllers\Api\V1\RestaurantSignalsController::class, 'excludeItem']);
                    Route::post('/integrations/pingwin/excluded-items/{itemId}/include', [\App\Http\Controllers\Api\V1\RestaurantMarketingDataController::class, 'includeItem'])->whereNumber('itemId');
                    Route::post('/integrations/pingwin/signals/post', [\App\Http\Controllers\Api\V1\RestaurantSignalsController::class, 'createPost']);
                    // Bússola (antes "O que publicar e quando"): página, criar publicação e sugerir texto a partir de uma jogada.
                    Route::get('/marketing/bussola', [\App\Http\Controllers\Api\V1\RestaurantCompassController::class, 'show']);
                    Route::post('/marketing/bussola/post', [\App\Http\Controllers\Api\V1\RestaurantCompassController::class, 'createPost']);
                    Route::post('/marketing/bussola/caption', [\App\Http\Controllers\Api\V1\RestaurantCompassController::class, 'caption'])->middleware('throttle:20,1');
                    Route::get('/marketing/bussola/caption/{requestId}', [\App\Http\Controllers\Api\V1\RestaurantCompassController::class, 'captionStatus'])->whereNumber('requestId');
                    Route::get('/integrations/pingwin/family-categories', [\App\Http\Controllers\Api\V1\RestaurantMarketingDataController::class, 'families']);
                    Route::post('/integrations/pingwin/family-categories/ai-suggest', [\App\Http\Controllers\Api\V1\RestaurantMarketingDataController::class, 'suggest'])->middleware('throttle:10,1');
                    Route::put('/integrations/pingwin/family-categories', [\App\Http\Controllers\Api\V1\RestaurantMarketingDataController::class, 'confirm']);
                    Route::put('/integrations/pingwin/annual-locals', [\App\Http\Controllers\Api\V1\RestaurantMarketingDataController::class, 'annualLocals']);
                    // Cadastro manual de lojas (desbloqueia o "Stores" do relatório).
                    Route::get('/integrations/pingwin/locations', [CompanyPingwinController::class, 'listLocations']);
                    Route::post('/integrations/pingwin/locations', [CompanyPingwinController::class, 'storeLocation']);
                    Route::patch('/integrations/pingwin/locations/{locationId}', [CompanyPingwinController::class, 'updateLocation']);
                    Route::delete('/integrations/pingwin/locations/{locationId}', [CompanyPingwinController::class, 'deleteLocation']);
                    // CoverManager — token AO NÍVEL DA EMPRESA (Integrações).
                    Route::get('/integrations/covermanager', [CompanyPingwinController::class, 'coverManagerIndex']);
                    Route::post('/integrations/covermanager/connect', [CompanyPingwinController::class, 'coverManagerConnect'])->middleware('block_when_impersonating');
                    Route::delete('/integrations/covermanager', [CompanyPingwinController::class, 'coverManagerDisconnect'])->middleware('block_when_impersonating');
                    // CoverManager (reservas) — Etapa 1: sincroniza o agregado por turno.
                    Route::post('/integrations/covermanager/sync', [CompanyPingwinController::class, 'coverManagerSync']);
                    // Sincronização por PERÍODO (um job por dia; 1 notificação no fim).
                    Route::post('/integrations/restaurant/sync-period', [CompanyPingwinController::class, 'syncPeriod']);
                    // CoverManager — Etapa 2: flag do ticket médio (por empresa).
                    Route::get('/integrations/covermanager/settings', [CompanyPingwinController::class, 'coverManagerSettings']);
                    Route::patch('/integrations/covermanager/settings', [CompanyPingwinController::class, 'updateCoverManagerSettings']);
                });

                // Utilizadores: listar e ver funcionam em impersonation (ex.: seletor "Vendedor" da
                // ficha da viatura); criar e alterar contas/password ficam bloqueados. Sem DELETE:
                // retirar o acesso faz-se no colaborador (/collaborators/{id}/access/revoke).
                // Agência gestora: ver (quem tem acesso), terminar (só o admin da empresa) e
                // convidar o primeiro admin de uma empresa sem nenhum (só a agência ou o root).
                Route::get('/management', [\App\Http\Controllers\Api\V1\CompanyManagementController::class, 'show']);
                // Pedidos de gestão recebidos: só os admins da própria empresa veem, aceitam e recusam.
                Route::get('/management/requests', [\App\Http\Controllers\Api\V1\CompanyManagementController::class, 'requests']);
                Route::middleware('block_when_impersonating')->group(function () {
                    Route::post('/management/requests/{requestId}/accept', [\App\Http\Controllers\Api\V1\CompanyManagementController::class, 'accept'])->whereNumber('requestId');
                    Route::post('/management/requests/{requestId}/decline', [\App\Http\Controllers\Api\V1\CompanyManagementController::class, 'decline'])->whereNumber('requestId');
                    Route::post('/management/connections', [\App\Http\Controllers\Api\V1\CompanyManagementController::class, 'decideConnections']);
                    Route::delete('/management', [\App\Http\Controllers\Api\V1\CompanyManagementController::class, 'end']);
                    Route::post('/management/first-admin', [\App\Http\Controllers\Api\V1\CompanyManagementController::class, 'inviteFirstAdmin']);
                });

                // Dashboard base (empresas sem viaturas nem restauração): Linha Editorial e seguidores, pelos módulos.
                Route::get('/dashboard/base', [\App\Http\Controllers\Api\V1\CompanyBaseDashboardController::class, 'show']);

                // Cobranças da XPLENDOR (só a própria empresa e o root; sem o módulo de Finanças).
                Route::get('/xplendor-charges', [\App\Http\Controllers\Api\V1\CompanyChargeController::class, 'index']);
                Route::get('/xplendor-charges/{chargeId}/invoice', [\App\Http\Controllers\Api\V1\CompanyChargeController::class, 'invoice'])->whereNumber('chargeId');
                Route::post('/xplendor-charges/{chargeId}/paid', [\App\Http\Controllers\Api\V1\CompanyChargeController::class, 'paid'])->whereNumber('chargeId')
                    ->middleware(['block_when_impersonating', 'throttle:charge-public-action']);

                Route::get('/users', [UserController::class, 'index']);
                Route::get('/users/{user}', [UserController::class, 'show']);
                Route::post('/users', [UserController::class, 'store'])->middleware('block_when_impersonating');
                Route::match(['put', 'patch'], '/users/{user}', [UserController::class, 'update'])->middleware('block_when_impersonating');

                // Colaboradores (equipa) e departamentos. Conteúdo: permitido em impersonation.
                // Acessos à plataforma: só o admin da própria empresa, bloqueado em impersonation.
                Route::get('/collaborators', [CollaboratorController::class, 'index']);
                Route::post('/collaborators', [CollaboratorController::class, 'store']);
                Route::get('/collaborators/{collaborator}', [CollaboratorController::class, 'show'])->whereNumber('collaborator');
                Route::match(['put', 'patch'], '/collaborators/{collaborator}', [CollaboratorController::class, 'update'])->whereNumber('collaborator');
                Route::delete('/collaborators/{collaborator}', [CollaboratorController::class, 'destroy'])->whereNumber('collaborator');
                Route::post('/collaborators/{collaborator}/photo', [CollaboratorController::class, 'storePhoto'])->whereNumber('collaborator');
                Route::delete('/collaborators/{collaborator}/photo', [CollaboratorController::class, 'deletePhoto'])->whereNumber('collaborator');
                Route::post('/collaborators/{collaborator}/deactivate', [CollaboratorController::class, 'deactivate'])->whereNumber('collaborator');
                Route::post('/collaborators/{collaborator}/activate', [CollaboratorController::class, 'activate'])->whereNumber('collaborator');
                Route::middleware('block_when_impersonating')->group(function () {
                    Route::post('/collaborators/{collaborator}/access', [CollaboratorController::class, 'grantAccess'])->whereNumber('collaborator');
                    Route::post('/collaborators/{collaborator}/access/resend', [CollaboratorController::class, 'resendInvite'])->whereNumber('collaborator');
                    Route::delete('/collaborators/{collaborator}/access/invite', [CollaboratorController::class, 'cancelInvite'])->whereNumber('collaborator');
                    Route::post('/collaborators/{collaborator}/access/revoke', [CollaboratorController::class, 'revokeAccess'])->whereNumber('collaborator');
                    Route::post('/collaborators/{collaborator}/access/restore', [CollaboratorController::class, 'restoreAccess'])->whereNumber('collaborator');
                });
                Route::get('/departments', [CompanyDepartmentController::class, 'index']);
                Route::post('/departments', [CompanyDepartmentController::class, 'store']);
                Route::post('/departments/suggested', [CompanyDepartmentController::class, 'suggested']);
                Route::match(['put', 'patch'], '/departments/{department}', [CompanyDepartmentController::class, 'update'])->whereNumber('department');
                Route::delete('/departments/{department}', [CompanyDepartmentController::class, 'destroy'])->whereNumber('department');
                // ── Módulo STOCK (Fase 3: recusa 403 se não ativo) ──
                Route::post('/cars/generate-description', [CarController::class, 'generateDescription'])->middleware('ensure_module:stock');
                Route::apiResource('/cars', CarController::class)->middleware('ensure_module:stock');
                // ── Módulo COMERCIAL/CRM ──
                Route::apiResource('/leads', CarLeadController::class)->only(['index', 'update'])->middleware('ensure_module:commercial_crm');
                Route::apiResource('/carmine-connection', CarmineConnectionController::class)->except('index')->middleware(['ensure_module:stock', 'block_when_impersonating']);
                // Blog: conteúdo (empresa, root e impersonation) e fluxo de aprovação. Aprovar,
                // devolver e voltar a rascunho: só o admin da própria empresa, fora de impersonation.
                Route::apiResource('/blogs', BlogController::class)->where(['blog' => '[0-9]+']);
                Route::delete('/blogs/{blog}/banner', [BlogController::class, 'destroyBanner'])->whereNumber('blog');
                Route::post('/blogs/{blog}/submit', [BlogController::class, 'submit'])->whereNumber('blog');
                Route::middleware('block_when_impersonating')->group(function () {
                    Route::post('/blogs/{blog}/approve', [BlogController::class, 'approve'])->whereNumber('blog');
                    Route::post('/blogs/{blog}/request-changes', [BlogController::class, 'requestChanges'])->whereNumber('blog');
                    Route::post('/blogs/{blog}/back-to-draft', [BlogController::class, 'backToDraft'])->whereNumber('blog');
                });
                Route::get('/blog-ai/context', [\App\Http\Controllers\Api\V1\BlogAiController::class, 'context']);
                Route::post('/blog-ai/drafts', [\App\Http\Controllers\Api\V1\BlogAiController::class, 'store'])->middleware('throttle:10,1');
                Route::get('/blog-ai/drafts/{draft}', [\App\Http\Controllers\Api\V1\BlogAiController::class, 'show'])->whereNumber('draft');
                // Pedidos à IA à espera (retomar ao voltar à página) e descartar.
                Route::get('/ai-requests/latest', [\App\Http\Controllers\Api\V1\AiRequestController::class, 'latest']);
                Route::post('/ai-requests/{requestId}/dismiss', [\App\Http\Controllers\Api\V1\AiRequestController::class, 'dismiss'])->whereNumber('requestId');
                Route::get('/brand-profile', [\App\Http\Controllers\Api\V1\BrandProfileController::class, 'show']);
                Route::put('/brand-profile', [\App\Http\Controllers\Api\V1\BrandProfileController::class, 'update']);
                // Seguidores (Perfil da Marca): estado, crescimento e registo manual de hoje.
                Route::get('/followers', [\App\Http\Controllers\Api\V1\FollowerSnapshotController::class, 'index']);
                Route::post('/followers', [\App\Http\Controllers\Api\V1\FollowerSnapshotController::class, 'store']);
                // "Sugerir perfil" (IA): pede e consulta; nunca grava o perfil.
                Route::post('/brand-profile/suggestions', [\App\Http\Controllers\Api\V1\BrandProfileSuggestionController::class, 'store'])->middleware('throttle:10,1');
                Route::get('/brand-profile/suggestions/{suggestionId}', [\App\Http\Controllers\Api\V1\BrandProfileSuggestionController::class, 'show'])->whereNumber('suggestionId');
                // ── Módulo LINHA EDITORIAL (transversal) — escolha de ramo + calendário herdado ──
                Route::middleware('ensure_module:linha_editorial')->group(function () {
                    Route::get('/editorial/sectors', [\App\Http\Controllers\Api\V1\EditorialLineController::class, 'sectors']);
                    Route::post('/editorial/sector', [\App\Http\Controllers\Api\V1\EditorialLineController::class, 'setSector']);
                    // B3b — TROCA de ramo (destrutiva). SENSÍVEL: bloqueado em impersonation.
                    Route::put('/editorial/sector', [\App\Http\Controllers\Api\V1\EditorialLineController::class, 'changeSector'])->middleware('block_when_impersonating');
                    Route::get('/editorial/calendar', [\App\Http\Controllers\Api\V1\EditorialLineController::class, 'calendar']);
                    // B2 — máquina de estados dos meses (abrir/fechar em sequência, com cascata).
                    Route::post('/editorial/months/{year}/{month}/open', [\App\Http\Controllers\Api\V1\EditorialLineController::class, 'openMonth'])
                        ->whereNumber('year')->whereNumber('month')->middleware('editorial_producer');
                    Route::post('/editorial/months/{year}/{month}/close', [\App\Http\Controllers\Api\V1\EditorialLineController::class, 'closeMonth'])
                        ->whereNumber('year')->whereNumber('month')->middleware('editorial_producer');
                    // B3a — esconder/mostrar HERDADAS (id de content_anchors) por ocorrência.
                    Route::post('/editorial/anchors/{anchorId}/hide', [\App\Http\Controllers\Api\V1\EditorialLineController::class, 'hideAnchor'])
                        ->whereNumber('anchorId')->middleware('editorial_producer');
                    Route::post('/editorial/anchors/{anchorId}/show', [\App\Http\Controllers\Api\V1\EditorialLineController::class, 'showAnchor'])
                        ->whereNumber('anchorId')->middleware('editorial_producer');
                    // B3a — criar/apagar PRÓPRIAS (id de editorial_own_anchors — espaço distinto).
                    Route::post('/editorial/anchors', [\App\Http\Controllers\Api\V1\EditorialLineController::class, 'createOwnAnchor'])->middleware('editorial_producer');
                    Route::delete('/editorial/own-anchors/{ownAnchorId}', [\App\Http\Controllers\Api\V1\EditorialLineController::class, 'deleteOwnAnchor'])
                        ->whereNumber('ownAnchorId')->middleware('editorial_producer');
                    // P1 — PUBLICAÇÕES (espaço de id distinto das âncoras).
                    Route::post('/editorial/posts', [\App\Http\Controllers\Api\V1\EditorialLineController::class, 'createPost']);
                    Route::put('/editorial/posts/{postId}', [\App\Http\Controllers\Api\V1\EditorialLineController::class, 'updatePost'])
                        ->whereNumber('postId');
                    Route::delete('/editorial/posts/{postId}', [\App\Http\Controllers\Api\V1\EditorialLineController::class, 'deletePost'])
                        ->whereNumber('postId');
                    // "Sugerir criativo" (IA) e criativo aceite campo a campo.
                    Route::post('/editorial/posts/{postId}/creative-suggestions', [\App\Http\Controllers\Api\V1\EditorialCreativeController::class, 'suggest'])
                        ->whereNumber('postId')->middleware('throttle:20,1');
                    Route::get('/editorial/posts/{postId}/creative-suggestions/{suggestionId}', [\App\Http\Controllers\Api\V1\EditorialCreativeController::class, 'suggestion'])
                        ->whereNumber('postId')->whereNumber('suggestionId');
                    // "Gerar legenda" (IA, com as imagens da versão atual): propostas, nunca gravadas.
                    Route::post('/editorial/posts/{postId}/caption-suggestions', [\App\Http\Controllers\Api\V1\EditorialCaptionController::class, 'suggest'])
                        ->whereNumber('postId')->middleware('throttle:20,1');
                    Route::get('/editorial/posts/{postId}/caption-suggestions/{suggestionId}', [\App\Http\Controllers\Api\V1\EditorialCaptionController::class, 'suggestion'])
                        ->whereNumber('postId')->whereNumber('suggestionId');
                    Route::get('/editorial/posts/{postId}/creative', [\App\Http\Controllers\Api\V1\EditorialCreativeController::class, 'show'])
                        ->whereNumber('postId');
                    Route::put('/editorial/posts/{postId}/creative', [\App\Http\Controllers\Api\V1\EditorialCreativeController::class, 'accept'])
                        ->whereNumber('postId');
                    // F3a: produção e aprovação (Kanban, versões, comentários, histórico). Aprovar e
                    // pedir alterações: aprovadores da empresa, nunca em impersonation.
                    Route::get('/editorial/board', [\App\Http\Controllers\Api\V1\EditorialWorkflowController::class, 'board']);
                    Route::get('/editorial/posts/{postId}/workflow', [\App\Http\Controllers\Api\V1\EditorialWorkflowController::class, 'show'])->whereNumber('postId');
                    Route::post('/editorial/posts/{postId}/move', [\App\Http\Controllers\Api\V1\EditorialWorkflowController::class, 'move'])->whereNumber('postId');
                    Route::put('/editorial/posts/{postId}/content', [\App\Http\Controllers\Api\V1\EditorialWorkflowController::class, 'content'])->whereNumber('postId');
                    Route::post('/editorial/posts/{postId}/comments', [\App\Http\Controllers\Api\V1\EditorialWorkflowController::class, 'comment'])->whereNumber('postId')->middleware('throttle:30,1');
                    Route::middleware('block_when_impersonating')->group(function () {
                        Route::post('/editorial/posts/{postId}/approve', [\App\Http\Controllers\Api\V1\EditorialWorkflowController::class, 'approve'])->whereNumber('postId');
                        Route::post('/editorial/posts/{postId}/request-changes', [\App\Http\Controllers\Api\V1\EditorialWorkflowController::class, 'requestChanges'])->whereNumber('postId');
                        Route::post('/editorial/approvals/approve-all', [\App\Http\Controllers\Api\V1\EditorialWorkflowController::class, 'approveAll']);
                        Route::put('/editorial/approvers/{userId}', [\App\Http\Controllers\Api\V1\EditorialWorkflowController::class, 'setApprover'])->whereNumber('userId');
                    });
                    Route::get('/editorial/workflow-settings', [\App\Http\Controllers\Api\V1\EditorialWorkflowController::class, 'settings']);
                    Route::put('/editorial/workflow-settings', [\App\Http\Controllers\Api\V1\EditorialWorkflowController::class, 'updateSettings']);
                    // F3b: media (envio em partes de 8 MB, retomável), media da versão e grelha do Instagram.
                    Route::post('/editorial/media/uploads', [\App\Http\Controllers\Api\V1\EditorialMediaController::class, 'startUpload'])->middleware('throttle:60,1');
                    Route::get('/editorial/media/uploads/{uploadId}', [\App\Http\Controllers\Api\V1\EditorialMediaController::class, 'uploadStatus'])->whereUuid('uploadId');
                    Route::post('/editorial/media/uploads/{uploadId}/chunk', [\App\Http\Controllers\Api\V1\EditorialMediaController::class, 'chunk'])->whereUuid('uploadId')->middleware('throttle:240,1');
                    Route::get('/editorial/media/{assetId}', [\App\Http\Controllers\Api\V1\EditorialMediaController::class, 'asset'])->whereNumber('assetId');
                    Route::put('/editorial/posts/{postId}/media', [\App\Http\Controllers\Api\V1\EditorialMediaController::class, 'setPostMedia'])->whereNumber('postId');
                    Route::get('/editorial/grid', [\App\Http\Controllers\Api\V1\EditorialMediaController::class, 'grid']);
                    // F3d: para publicar hoje, marcar como publicada, resultados à mão e do mês.
                    Route::get('/editorial/today', [\App\Http\Controllers\Api\V1\EditorialPublishingController::class, 'today']);
                    Route::get('/editorial/results', [\App\Http\Controllers\Api\V1\EditorialPublishingController::class, 'results']);
                    Route::post('/editorial/posts/{postId}/published', [\App\Http\Controllers\Api\V1\EditorialPublishingController::class, 'markPublished'])->whereNumber('postId');
                    Route::post('/editorial/posts/{postId}/skip-network', [\App\Http\Controllers\Api\V1\EditorialPublishingController::class, 'skipNetwork'])->whereNumber('postId');
                    Route::get('/editorial/formats', fn () => \App\Helpers\ApiResponse::success(\App\Services\Editorial\NetworkFormats::table(), 'Formatos por rede.'));
                    Route::put('/editorial/posts/{postId}/results', [\App\Http\Controllers\Api\V1\EditorialPublishingController::class, 'saveResults'])->whereNumber('postId');
                    // F3c: links de aprovação por lote (rotas fixas antes de /{linkId}).
                    Route::get('/editorial/review-links', [\App\Http\Controllers\Api\V1\ContentReviewLinkController::class, 'index']);
                    Route::get('/editorial/review-links/candidates', [\App\Http\Controllers\Api\V1\ContentReviewLinkController::class, 'candidates']);
                    Route::post('/editorial/review-links', [\App\Http\Controllers\Api\V1\ContentReviewLinkController::class, 'store'])->middleware('throttle:30,1');
                    Route::put('/editorial/review-links/{linkId}', [\App\Http\Controllers\Api\V1\ContentReviewLinkController::class, 'update'])->whereNumber('linkId')->middleware('throttle:30,1');
                    Route::post('/editorial/review-links/{linkId}/extend', [\App\Http\Controllers\Api\V1\ContentReviewLinkController::class, 'extend'])->whereNumber('linkId');
                    Route::post('/editorial/review-links/{linkId}/revoke', [\App\Http\Controllers\Api\V1\ContentReviewLinkController::class, 'revoke'])->whereNumber('linkId');
                    Route::get('/editorial/review-links/{linkId}/preview', [\App\Http\Controllers\Api\V1\ContentReviewLinkController::class, 'preview'])->whereNumber('linkId');
                    // "Gerar ideias do mês" (IA) e aceitação ideia a ideia.
                    Route::post('/editorial/ideas', [\App\Http\Controllers\Api\V1\EditorialIdeasController::class, 'store'])->middleware('throttle:10,1');
                    Route::get('/editorial/ideas/{requestId}', [\App\Http\Controllers\Api\V1\EditorialIdeasController::class, 'show'])->whereNumber('requestId');
                    Route::post('/editorial/ideas/{requestId}/accept', [\App\Http\Controllers\Api\V1\EditorialIdeasController::class, 'accept'])->whereNumber('requestId');
                });

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
                // Orçamentos-em-tickets do stand (site_change): lista+pipeline e aprovar pacote.
                // ⚠️ ANTES de /{ticket} para "quotes" não ser capturado como {ticket}.
                Route::get('/support-tickets/quotes', [SupportTicketController::class, 'quotes']);
                Route::post('/support-tickets/quotes/approve', [SupportTicketController::class, 'approveQuotePackage']);
                Route::get('/support-tickets/{ticket}', [SupportTicketController::class, 'show']);
                Route::post('/support-tickets/{ticket}/messages', [SupportTicketController::class, 'storeMessage']);
                // Site_change (pago): o stand só aprova/rejeita o orçamento.
                Route::patch('/support-tickets/{ticket}/quote-decision', [SupportTicketController::class, 'quoteDecision']);

                // Orçamentos da XPLENDOR ligados a esta empresa: vê os enviados, decide e descarrega o PDF.
                Route::get('/quotes', [QuoteController::class, 'index']);
                Route::patch('/quotes/{quote}/decision', [QuoteController::class, 'decision']);
                Route::get('/quotes/{quote}/pdf', [QuoteController::class, 'pdf'])->whereNumber('quote');

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

                // Motor de recomendações (regras explicáveis), por ramo.
                Route::get('recommendations', [\App\Http\Controllers\Api\V1\RecommendationController::class, 'index']);

                // OAuth Meta
                Route::get('integrations/meta/oauth-url', [MetaOAuthController::class, 'getAuthUrl']);
                // (integrations, DELETE integrations/meta e adsets estão definidas acima,
                // uma só vez; o DELETE com block_when_impersonating.)
                // Escolher a conta de anúncios APÓS o OAuth (o callback no backend
                // guarda o token mas não pode perguntar o account_id). A página de
                // integrações em /app define-o aqui.
                Route::patch('integrations/meta/account', [CompanyIntegrationController::class, 'setMetaAccount'])
                    ->middleware('block_when_impersonating');
            });

            // Vista da agência: a Linha Editorial de todos os clientes que a pessoa vê, o painel por
            // cliente, as atribuições e os pedidos de nova empresa gerida. Portão: agency.
            Route::prefix('/agencies/{agency}')->whereNumber('agency')->middleware('agency')->group(function () {
                $c = \App\Http\Controllers\Api\V1\Agency\AgencyController::class;
                Route::get('/companies', [$c, 'companies']);
                Route::get('/editorial/calendar', [$c, 'posts']);
                Route::get('/editorial/board', [$c, 'posts']);
                Route::get('/editorial/today', [$c, 'today']);
                Route::get('/editorial/awaiting', [$c, 'awaiting']);
                Route::get('/editorial/results', [$c, 'results']);
                Route::get('/panel', [$c, 'panel']);
                Route::get('/assignments', [$c, 'assignments']);
                Route::put('/assignments/{companyId}', [$c, 'assign'])->whereNumber('companyId');
                Route::get('/company-requests', [$c, 'requests']);
                Route::post('/company-requests', [$c, 'storeRequest'])->middleware('block_when_impersonating');
                // Pedir a gestão de uma empresa existente (F1d) e terminar a relação com um cliente.
                Route::get('/management-requests', [$c, 'managementRequests']);
                Route::get('/billing', [$c, 'billing']);
                Route::middleware('block_when_impersonating')->group(function () use ($c) {
                    Route::post('/management-requests', [$c, 'storeManagementRequest'])->middleware('throttle:20,1');
                    Route::post('/management-requests/{requestId}/withdraw', [$c, 'withdrawManagementRequest'])->whereNumber('requestId');
                    Route::post('/managed/{companyId}/end', [$c, 'endManagement'])->whereNumber('companyId');
                });
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
            // Regras de formato (referência de mercado) do "Sugerir criativo": só o root.
            Route::get('/creative-format-rules', [\App\Http\Controllers\Api\V1\Admin\CreativeFormatRuleController::class, 'index']);
            Route::post('/creative-format-rules', [\App\Http\Controllers\Api\V1\Admin\CreativeFormatRuleController::class, 'store']);
            Route::put('/creative-format-rules/{ruleId}', [\App\Http\Controllers\Api\V1\Admin\CreativeFormatRuleController::class, 'update'])->whereNumber('ruleId');
            Route::delete('/creative-format-rules/{ruleId}', [\App\Http\Controllers\Api\V1\Admin\CreativeFormatRuleController::class, 'destroy'])->whereNumber('ruleId');
            // Modelos de IA por função (o OCR não entra), histórico, custos e teste às cegas: só o root.
            Route::get('/ai-models', [\App\Http\Controllers\Api\V1\Admin\AiModelsController::class, 'index']);
            Route::put('/ai-models/{function}', [\App\Http\Controllers\Api\V1\Admin\AiModelsController::class, 'update'])->where('function', '[a-z_]+');
            Route::post('/ai-models/apply-all', [\App\Http\Controllers\Api\V1\Admin\AiModelsController::class, 'applyAll']);
            Route::get('/ai-blind-tests', [\App\Http\Controllers\Api\V1\Admin\AiModelsController::class, 'blindTests']);
            Route::post('/ai-blind-tests', [\App\Http\Controllers\Api\V1\Admin\AiModelsController::class, 'createBlindTest'])->middleware('throttle:10,1');
            Route::get('/ai-blind-tests/{testId}', [\App\Http\Controllers\Api\V1\Admin\AiModelsController::class, 'blindTest'])->whereNumber('testId');
            Route::post('/ai-blind-tests/{testId}/cases/{caseId}/choice', [\App\Http\Controllers\Api\V1\Admin\AiModelsController::class, 'chooseBlindCase'])->whereNumber('testId')->whereNumber('caseId');
            // Contagens transversais para o dashboard root (users + carros da plataforma).
            Route::get('/platform/summary', [AdminController::class, 'platformSummary']);

            // IMPERSONATION — INICIAR (só root). Emite token de impersonation p/ o user-alvo.
            Route::post('/impersonation/start', [\App\Http\Controllers\Api\V1\ImpersonationController::class, 'start']);

            // Tickets de suporte — TRANSVERSAL (todas as empresas).
            Route::get('/tickets/summary', [AdminSupportTicketController::class, 'summary']);
            // Pipeline de orçamentos-em-tickets (site_change) — "em cima da mesa" por estado.
            Route::get('/tickets/quote-pipeline', [AdminSupportTicketController::class, 'quotePipeline']);
            Route::get('/tickets', [AdminSupportTicketController::class, 'index']);
            Route::get('/tickets/{ticket}', [AdminSupportTicketController::class, 'show']);
            Route::patch('/tickets/{ticket}/status', [AdminSupportTicketController::class, 'updateStatus']);
            // Tarefas do ticket de arranque (orçamento aceite): marcar e desmarcar.
            Route::patch('/tickets/{ticket}/tasks/{task}', [AdminSupportTicketController::class, 'updateTask'])->whereNumber(['ticket', 'task']);
            Route::patch('/tickets/{ticket}/type', [AdminSupportTicketController::class, 'reclassify'])->middleware('block_when_impersonating');
            Route::post('/tickets/{ticket}/messages', [AdminSupportTicketController::class, 'storeMessage']);
            // Site_change (pago): orçar, marcar pago (+ fatura PDF), concluir.
            Route::patch('/tickets/{ticket}/quote', [AdminSupportTicketController::class, 'setQuote']);
            Route::post('/tickets/{ticket}/mark-paid', [AdminSupportTicketController::class, 'markPaid']);
            Route::patch('/tickets/{ticket}/complete', [AdminSupportTicketController::class, 'markCompleted']);

            // Orçamentos de serviços da XPLENDOR (só a equipa). Rotas fixas antes de /{quote}.
            Route::get('/quotes/summary', [AdminQuoteController::class, 'summary']);
            Route::get('/quotes/defaults', [AdminQuoteController::class, 'defaults']);
            Route::get('/quotes/companies', [AdminQuoteController::class, 'companies']);
            Route::get('/quotes/customers', [AdminQuoteController::class, 'customers']);
            Route::post('/quotes/customers', [AdminQuoteController::class, 'storeCustomer']);
            Route::get('/quotes', [AdminQuoteController::class, 'index']);
            Route::post('/quotes', [AdminQuoteController::class, 'store']);
            Route::get('/quotes/{quote}', [AdminQuoteController::class, 'show'])->whereNumber('quote');
            Route::match(['put', 'patch'], '/quotes/{quote}', [AdminQuoteController::class, 'update'])->whereNumber('quote');
            Route::post('/quotes/{quote}/send', [AdminQuoteController::class, 'send'])->whereNumber('quote');
            Route::patch('/quotes/{quote}/decision', [AdminQuoteController::class, 'decision'])->whereNumber('quote');
            Route::post('/quotes/{quote}/duplicate', [AdminQuoteController::class, 'duplicate'])->whereNumber('quote');
            Route::get('/quotes/{quote}/pdf', [AdminQuoteController::class, 'previewPdf'])->whereNumber('quote');
            Route::get('/quotes/{quote}/versions/{version}/pdf', [AdminQuoteController::class, 'versionPdf'])->whereNumber(['quote', 'version']);
            Route::delete('/quotes/{quote}', [AdminQuoteController::class, 'destroy'])->whereNumber('quote');
            // Link público, aberturas e respostas; pré-visualização da página pública (não conta).
            Route::get('/quotes/{quote}/activity', [AdminQuoteController::class, 'activity'])->whereNumber('quote');
            Route::get('/quotes/{quote}/versions/{version}/public-preview', [AdminQuoteController::class, 'publicPreview'])->whereNumber(['quote', 'version']);
            // Catálogo de serviços (tabela padrão dos orçamentos).
            Route::get('/service-catalog', [AdminServiceCatalogController::class, 'index']);
            Route::post('/service-catalog', [AdminServiceCatalogController::class, 'store']);
            Route::match(['put', 'patch'], '/service-catalog/{id}', [AdminServiceCatalogController::class, 'update'])->whereNumber('id');

            // Stock GLOBAL — 1ª vista de dados transversais (veículos de todas
            // as empresas ATIVAS). Só leitura; não toca nos endpoints de stand.
            Route::get('/stock/summary', [AdminStockController::class, 'summary']);
            Route::get('/stock/companies', [AdminStockController::class, 'companies']);
            Route::get('/stock', [AdminStockController::class, 'index']);

            // Empresas (transversal) — lista + utilizadores por empresa (base da impersonation).
            Route::get('/companies', [AdminCompanyController::class, 'index']);
            Route::get('/companies/{company}/users', [AdminCompanyController::class, 'users'])->whereNumber('company');

            // Ativar/inativar empresa (root). Inativar tira acesso + exclui do stock.
            Route::patch('/companies/{company}/status', [AdminCompanyController::class, 'setStatus']);

            // Módulos por empresa (Incremento 1) — só super-admin.
            Route::get('/companies/{company}/modules', [AdminCompanyController::class, 'modules']);
            Route::patch('/companies/{company}/modules', [AdminCompanyController::class, 'setModule']);
            Route::post('/companies/{company}/modules/preset', [AdminCompanyController::class, 'applyModulePreset']);

            // Pedidos de nova empresa gerida (feitos pelas agências).
            Route::get('/company-requests', [\App\Http\Controllers\Api\V1\Admin\ManagedCompanyRequestController::class, 'index']);
            Route::post('/company-requests/{requestId}/approve', [\App\Http\Controllers\Api\V1\Admin\ManagedCompanyRequestController::class, 'approve'])->whereNumber('requestId');
            Route::post('/company-requests/{requestId}/decline', [\App\Http\Controllers\Api\V1\Admin\ManagedCompanyRequestController::class, 'decline'])->whereNumber('requestId');

            // Cobranças da XPLENDOR (todas as empresas).
            Route::get('/charges', [\App\Http\Controllers\Api\V1\Admin\ChargeController::class, 'index']);
            Route::post('/charges', [\App\Http\Controllers\Api\V1\Admin\ChargeController::class, 'store']);
            Route::get('/charges/{chargeId}', [\App\Http\Controllers\Api\V1\Admin\ChargeController::class, 'show'])->whereNumber('chargeId');
            Route::get('/charges/{chargeId}/invoice', [\App\Http\Controllers\Api\V1\Admin\ChargeController::class, 'invoice'])->whereNumber('chargeId');
            Route::get('/charges/{chargeId}/proof', [\App\Http\Controllers\Api\V1\Admin\ChargeController::class, 'proof'])->whereNumber('chargeId');
            Route::post('/charges/{chargeId}/paid', [\App\Http\Controllers\Api\V1\Admin\ChargeController::class, 'markPaid'])->whereNumber('chargeId');
            Route::post('/charges/{chargeId}/cancel', [\App\Http\Controllers\Api\V1\Admin\ChargeController::class, 'cancel'])->whereNumber('chargeId');
            Route::post('/charges/{chargeId}/refuse', [\App\Http\Controllers\Api\V1\Admin\ChargeController::class, 'refuse'])->whereNumber('chargeId');
            Route::post('/charges/{chargeId}/send', [\App\Http\Controllers\Api\V1\Admin\ChargeController::class, 'send'])->whereNumber('chargeId');

            // Agências: marcar a empresa como agência e definir, mudar ou retirar a agência gestora.
            Route::get('/agencies', [\App\Http\Controllers\Api\V1\Admin\CompanyManagementController::class, 'agencies']);
            Route::get('/companies/{company}/management', [\App\Http\Controllers\Api\V1\Admin\CompanyManagementController::class, 'show'])->whereNumber('company');
            Route::patch('/companies/{company}/agency', [\App\Http\Controllers\Api\V1\Admin\CompanyManagementController::class, 'setAgency'])->whereNumber('company');
            Route::put('/companies/{company}/management', [\App\Http\Controllers\Api\V1\Admin\CompanyManagementController::class, 'assign'])->whereNumber('company');
            Route::post('/companies/{company}/management/end', [\App\Http\Controllers\Api\V1\Admin\CompanyManagementController::class, 'end'])->whereNumber('company');
            Route::get('/management-requests', [\App\Http\Controllers\Api\V1\Admin\CompanyManagementController::class, 'requests']);
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

    // Equipa (secção Equipa dos sites): só colaboradores ativos, autorizados e marcados para o site.
    Route::get('team', [PublicTeamController::class, 'index'])->middleware('throttle:60,1');

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

// Orçamentos: página pública de uma versão enviada (sem login). O token (64 caracteres
// aleatórios) só dá acesso àquela versão e vem no cabeçalho X-Quote-Token, nunca no
// caminho (o link é /orcamento#<token>). Limites com nome (AppServiceProvider):
// leitura, sinal de abertura e respostas do cliente.
// Link seguro das cobranças da XPLENDOR (sem conta): token no cabeçalho X-Charge-Token.
Route::prefix('public/charge')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\Public\ChargePublicController::class, 'show'])->middleware('throttle:charge-public-read');
    Route::get('/pdf', [\App\Http\Controllers\Api\Public\ChargePublicController::class, 'pdf'])->middleware('throttle:charge-public-read');
    Route::post('/open', [\App\Http\Controllers\Api\Public\ChargePublicController::class, 'open'])->middleware('throttle:charge-public-open');
    Route::post('/paid', [\App\Http\Controllers\Api\Public\ChargePublicController::class, 'paid'])->middleware('throttle:charge-public-action');
});

Route::prefix('public/quote')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\Public\QuotePublicController::class, 'show'])->middleware('throttle:quote-public-read');
    Route::get('/pdf', [\App\Http\Controllers\Api\Public\QuotePublicController::class, 'pdf'])->middleware('throttle:quote-public-read');
    Route::post('/open', [\App\Http\Controllers\Api\Public\QuotePublicController::class, 'open'])->middleware('throttle:quote-public-open');
    Route::post('/accept', [\App\Http\Controllers\Api\Public\QuotePublicController::class, 'accept'])->middleware('throttle:quote-public-action');
    Route::post('/refuse', [\App\Http\Controllers\Api\Public\QuotePublicController::class, 'refuse'])->middleware('throttle:quote-public-action');
    Route::post('/request-changes', [\App\Http\Controllers\Api\Public\QuotePublicController::class, 'requestChanges'])->middleware('throttle:quote-public-action');
});

// Link de configuração do cliente (F1c), sem conta: o token vem no cabeçalho X-Setup-Token,
// nunca no caminho (o link é /configurar#<token>). Os callbacks da Meta são os de sempre.
Route::prefix('public/setup')->group(function () {
    $c = \App\Http\Controllers\Api\Public\SetupPublicController::class;
    Route::get('/', [$c, 'show'])->middleware('throttle:setup-public-read');
    Route::post('/open', [$c, 'open'])->middleware('throttle:setup-public-open');
    Route::get('/social/auth-url', [$c, 'socialAuthUrl'])->middleware('throttle:setup-public-action');
    Route::get('/social/candidates', [$c, 'socialCandidates'])->middleware('throttle:setup-public-action');
    Route::put('/social/accounts', [$c, 'socialSave'])->middleware('throttle:setup-public-action');
    Route::get('/ads/auth-url', [$c, 'adsAuthUrl'])->middleware('throttle:setup-public-action');
    Route::get('/ads/accounts', [$c, 'adsAccounts'])->middleware('throttle:setup-public-action');
    Route::put('/ads/account', [$c, 'adsSave'])->middleware('throttle:setup-public-action');
    Route::post('/ga4/verify', [$c, 'ga4Verify'])->middleware('throttle:setup-public-action');
});

// Link de aprovação de conteúdos por lote (F3c), sem conta: o token vem no cabeçalho
// X-Review-Token, nunca no caminho (o link é /aprovar#<token>). Os ficheiros só por URL
// assinado, limitado ao lote e à validade do link.
Route::prefix('public/review')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\Public\ContentReviewPublicController::class, 'show'])->middleware('throttle:review-public-read');
    Route::post('/open', [\App\Http\Controllers\Api\Public\ContentReviewPublicController::class, 'open'])->middleware('throttle:review-public-open');
    Route::post('/items/{item}/approve', [\App\Http\Controllers\Api\Public\ContentReviewPublicController::class, 'approve'])->whereNumber('item')->middleware('throttle:review-public-action');
    Route::post('/items/{item}/request-changes', [\App\Http\Controllers\Api\Public\ContentReviewPublicController::class, 'requestChanges'])->whereNumber('item')->middleware('throttle:review-public-action');
    Route::post('/items/{item}/comments', [\App\Http\Controllers\Api\Public\ContentReviewPublicController::class, 'comment'])->whereNumber('item')->middleware('throttle:review-public-action');
    Route::post('/approve-all', [\App\Http\Controllers\Api\Public\ContentReviewPublicController::class, 'approveAll'])->middleware('throttle:review-public-action');
    Route::get('/media/{link}/{asset}/{variant}', [\App\Http\Controllers\Api\Public\ContentReviewPublicController::class, 'media'])
        ->whereNumber('link')->whereNumber('asset')->where('variant', 'original|thumb|preview|poster')
        ->middleware(['signed:relative', 'throttle:600,1'])->name('review.media');
});
// Foto de perfil das contas ligadas (pré-visualização como na rede): só por URL assinado.
Route::get('social-avatar/{account}', [\App\Http\Controllers\Api\MediaFileController::class, 'avatar'])
    ->whereNumber('account')->middleware(['signed:relative', 'throttle:600,1'])->name('social.avatar');

// Media da Linha Editorial (disco privado): só por URL assinado de curta duração e relativo.
Route::get('media/{asset}/{variant}', [\App\Http\Controllers\Api\MediaFileController::class, 'show'])
    ->whereNumber('asset')->where('variant', 'original|thumb|preview|poster')
    ->middleware(['signed:relative', 'throttle:600,1'])->name('media.file');

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

    // Defesa em profundidade: resolve o caminho real e recusa tudo o que saia
    // da pasta pública do storage (../, %2F codificado, symlinks para fora).
    $publicRoot = realpath(storage_path('app/public'));
    $fullPath   = realpath(storage_path('app/public/' . $path));
    abort_unless(
        $publicRoot !== false
            && $fullPath !== false
            && str_starts_with($fullPath, $publicRoot . DIRECTORY_SEPARATOR)
            && is_file($fullPath),
        404
    );

    return Response::file($fullPath, [
        'Access-Control-Allow-Origin' => in_array($origin, $allowed) ? $origin : '',
        'Vary' => 'Origin',
    ]);
})->where('path', '.*');
