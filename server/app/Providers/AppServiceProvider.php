<?php

namespace App\Providers;

use App\Models\{
    Car,
    CarExternalImage,
    CarImage,
    CarLead,
    CarPerformanceMetric,
    CarSalePotentialScore,
    Company
};
use App\Observers\{
    CarObserver,
    CarImageObserver,
    CarPerformanceMetricObserver,
    CompanyObserver,
    LeadObserver
};
use App\Repositories\Contracts\{
    BlogRepositoryInterface,
    CarAiAnalysesRepositoryInterface,
    CarBrandRepositoryInterface,
    CarCategoryRepositoryInterface,
    CarDecisionRepositoryInterface,
    CarExternalImageRepositoryInterface,
    CarInteractionRepositoryInterface,
    CarLeadRepositoryInterface,
    CarMarketSnapshotRepositoryInterface,
    CarMarketingRoiRepositoryInterface,
    CompanyIntegrationRepositoryInterface,
    CarmineConnectionRepositoryInterface,
    CarModelRepositoryInterface,
    CarPerformanceMetricRepositoryInterface,
    CarRepositoryInterface,
    CarSaleRepositoryInterface,
    CarSalePotentialScoreRepositoryInterface,
    CarViewRepositoryInterface,
    CompanyRepositoryInterface,
    DashboardRepositoryInterface,
    DistrictRepositoryInterface,
    MunicipalityRepositoryInterface,
    NewsletterRepositoryInterface,
    ParishRepositoryInterface,
    PlanRepositoryInterface,
    CustomerRepositoryInterface,
    ExpenseCategoryRepositoryInterface,
    ExpenseRepositoryInterface,
    ScraperExecutionRepositoryInterface,
    SilentBuyerDetectionRepositoryInterface,
    SupplierRepositoryInterface,
    DocumentTemplateRepositoryInterface,
    SupportTicketRepositoryInterface,
    QuoteRepositoryInterface,
    CompanyTaskRepositoryInterface,
    VehicleAttributeRepositoryInterface,
    UserInviteRepositoryInterface,
    UserRepositoryInterface
};
use App\Repositories\{
    BlogRepository,
    CarAiAnalysesRepository,
    CarBrandRepository,
    CarCategoryRepository,
    CarDecisionRepository,
    CarExternalImageRepository,
    CarInteractionRepository,
    CarLeadRepository,
    CarMarketSnapshotRepository,
    CarMarketingRoiRepository,
    CompanyIntegrationRepository,
    CarmineConnectionRepository,
    CarModelRepository,
    CarPerformanceMetricRepository,
    CarRepository,
    CarSaleRepository,
    CarSalePotentialScoreRepository,
    CarViewRepository,
    CompanyRepository,
    DashboardRepository,
    DistrictRepository,
    MunicipalityRepository,
    NewsletterRepository,
    ParishRepository,
    PlanRepository,
    CustomerRepository,
    ExpenseCategoryRepository,
    ExpenseRepository,
    ScraperExecutionRepository,
    SilentBuyerDetectionRepository,
    SupplierRepository,
    DocumentTemplateRepository,
    SupportTicketRepository,
    QuoteRepository,
    CompanyTaskRepository,
    VehicleAttributeRepository,
    UserInviteRepository,
    UserRepository
};
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use App\Http\Middleware\CheckCompanyApiToken;
use App\Http\Middleware\CheckCompanySubscription;
use App\Http\Middleware\CheckScraperApiToken;
use App\Http\Middleware\ResolveReportToken;
use App\Http\Middleware\EnsureSuperAdmin;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Motor de recomendações (regras de especialista, explicáveis). Registo das
        // regras: para acrescentar uma regra nova, juntá-la a esta lista.
        $this->app->singleton(\App\Recommendations\RecommendationEngine::class, fn ($app) =>
            new \App\Recommendations\RecommendationEngine([
                $app->make(\App\Recommendations\Rules\StaleCustomerAudienceRule::class),
                // Ramo Automóvel (migradas do CarIssueEngine). A ordem desempata a
                // prioridade quando a mesma viatura cumpre várias regras.
                $app->make(\App\Recommendations\Rules\Automotive\SoldCarAdStillActiveRule::class),
                $app->make(\App\Recommendations\Rules\Automotive\PriceAboveMarketRule::class),
                $app->make(\App\Recommendations\Rules\Automotive\DeadStockRule::class),
                $app->make(\App\Recommendations\Rules\Automotive\LowDemandRule::class),
                $app->make(\App\Recommendations\Rules\Automotive\PoorListingRule::class),
                $app->make(\App\Recommendations\Rules\Automotive\HighViewsNoContactsRule::class),
                $app->make(\App\Recommendations\Rules\Automotive\SpendWithoutLeadRule::class),
            ]));

        // Fotografia do stock partilhada pelas regras do Automóvel: uma por pedido/job.
        $this->app->scoped(\App\Services\Automotive\AutomotiveStockSnapshot::class);

        $this->app->bind(BlogRepositoryInterface::class, BlogRepository::class);
        $this->app->bind(CarRepositoryInterface::class, CarRepository::class);
        $this->app->bind(CarAiAnalysesRepositoryInterface::class, CarAiAnalysesRepository::class);
        $this->app->bind(CarBrandRepositoryInterface::class, CarBrandRepository::class);
        $this->app->bind(CarCategoryRepositoryInterface::class, CarCategoryRepository::class);
        $this->app->bind(CarDecisionRepositoryInterface::class, CarDecisionRepository::class);
        $this->app->bind(CarExternalImageRepositoryInterface::class, CarExternalImageRepository::class);
        $this->app->bind(CarInteractionRepositoryInterface::class, CarInteractionRepository::class);
        $this->app->bind(CarLeadRepositoryInterface::class, CarLeadRepository::class);
        $this->app->bind(CarMarketSnapshotRepositoryInterface::class, CarMarketSnapshotRepository::class);
        $this->app->bind(CarMarketingRoiRepositoryInterface::class, CarMarketingRoiRepository::class);
        $this->app->bind(CompanyIntegrationRepositoryInterface::class, CompanyIntegrationRepository::class);
        $this->app->bind(CarModelRepositoryInterface::class, CarModelRepository::class);
        $this->app->bind(CarPerformanceMetricRepositoryInterface::class, CarPerformanceMetricRepository::class);
        $this->app->bind(CarSaleRepositoryInterface::class, CarSaleRepository::class);
        $this->app->bind(CarSalePotentialScoreRepositoryInterface::class, CarSalePotentialScoreRepository::class);
        $this->app->bind(CarmineConnectionRepositoryInterface::class, CarmineConnectionRepository::class);
        $this->app->bind(CarViewRepositoryInterface::class, CarViewRepository::class);
        $this->app->bind(CompanyRepositoryInterface::class, CompanyRepository::class);
        $this->app->bind(DashboardRepositoryInterface::class, DashboardRepository::class);
        $this->app->bind(DistrictRepositoryInterface::class, DistrictRepository::class);
        $this->app->bind(MunicipalityRepositoryInterface::class, MunicipalityRepository::class);
        $this->app->bind(NewsletterRepositoryInterface::class, NewsletterRepository::class);
        $this->app->bind(ParishRepositoryInterface::class, ParishRepository::class);
        $this->app->bind(PlanRepositoryInterface::class, PlanRepository::class);
        $this->app->bind(ScraperExecutionRepositoryInterface::class, ScraperExecutionRepository::class);
        $this->app->bind(SilentBuyerDetectionRepositoryInterface::class, SilentBuyerDetectionRepository::class);
        $this->app->bind(SupplierRepositoryInterface::class, SupplierRepository::class);
        $this->app->bind(DocumentTemplateRepositoryInterface::class, DocumentTemplateRepository::class);
        $this->app->bind(SupportTicketRepositoryInterface::class, SupportTicketRepository::class);
        $this->app->bind(QuoteRepositoryInterface::class, QuoteRepository::class);
        $this->app->bind(CompanyTaskRepositoryInterface::class, CompanyTaskRepository::class);
        // GA4 — o cliente REST da Data API por trás do contrato (testável via fake).
        $this->app->bind(\App\Services\Ga4\Ga4ClientInterface::class, \App\Services\Ga4\Ga4RestClient::class);
        $this->app->bind(CustomerRepositoryInterface::class, CustomerRepository::class);
        $this->app->bind(ExpenseCategoryRepositoryInterface::class, ExpenseCategoryRepository::class);
        $this->app->bind(ExpenseRepositoryInterface::class, ExpenseRepository::class);
        $this->app->bind(VehicleAttributeRepositoryInterface::class, VehicleAttributeRepository::class);
        $this->app->bind(UserRepositoryInterface::class, UserRepository::class);
        $this->app->bind(UserInviteRepositoryInterface::class, UserInviteRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // ────────────────────────────────────────────────────────────────────
        // Blindagem contra wipe acidental da BD (incidente 2026-06-09,
        // CLAUDE.md sec 14.3).
        //
        // Bloqueia migrate:fresh, migrate:refresh e db:wipe em TODOS os
        // ambientes EXCEPTO 'testing'. Em testing, o trait RefreshDatabase
        // precisa de chamar migrate:fresh internamente para preparar a SQLite
        // em memória — por isso a excepção.
        //
        // Bónus de segurança: se alguém voltar a fazer `config:cache` em dev,
        // a env cacheada vai dizer 'local' em vez de 'testing', a prohibição
        // activa-se durante a corrida de testes e o RefreshDatabase falha FAST
        // com mensagem clara — em vez de fazer wipe silencioso da BD de dev.
        //
        // Para correr `migrate:fresh` em dev de propósito: comentar esta linha
        // temporariamente. Em prod, nunca descomentar.
        // ────────────────────────────────────────────────────────────────────
        DB::prohibitDestructiveCommands(!$this->app->environment('testing'));

        Company::observe(CompanyObserver::class);
        Car::observe(CarObserver::class);
        CarLead::observe(LeadObserver::class);
        CarPerformanceMetric::observe(CarPerformanceMetricObserver::class);
        CarImage::observe(CarImageObserver::class);

        Route::aliasMiddleware('check_company_api_token', CheckCompanyApiToken::class);
        Route::aliasMiddleware('check_company_subscription', CheckCompanySubscription::class);
        Route::aliasMiddleware('check_scraper_api_token', CheckScraperApiToken::class);
        Route::aliasMiddleware('resolve_report_token', ResolveReportToken::class);
        Route::aliasMiddleware('ensure_super_admin', EnsureSuperAdmin::class);
        Route::aliasMiddleware('ensure_module', \App\Http\Middleware\EnsureModuleActive::class);
        Route::aliasMiddleware('block_when_impersonating', \App\Http\Middleware\BlockWhenImpersonating::class);
        Route::aliasMiddleware('editorial_producer', \App\Http\Middleware\EnsureEditorialProducer::class);
        Route::aliasMiddleware('tenant', \App\Http\Middleware\EnsureTenantAccess::class);

        // Página pública dos orçamentos: limites por IP (o IP só é usado aqui, de passagem,
        // e nunca é guardado). Respostas do cliente: também por token.
        \Illuminate\Support\Facades\RateLimiter::for('quote-public-read', fn (\Illuminate\Http\Request $r) => \Illuminate\Cache\RateLimiting\Limit::perMinute(60)->by('qr|' . $r->ip()));
        \Illuminate\Support\Facades\RateLimiter::for('quote-public-open', fn (\Illuminate\Http\Request $r) => \Illuminate\Cache\RateLimiting\Limit::perMinute(30)->by('qo|' . $r->ip()));
        \Illuminate\Support\Facades\RateLimiter::for('quote-public-action', fn (\Illuminate\Http\Request $r) => [
            \Illuminate\Cache\RateLimiting\Limit::perMinute(10)->by('qa|' . $r->ip()),
            \Illuminate\Cache\RateLimiting\Limit::perHour(20)->by('qt|' . (string) $r->route('token')),
        ]);
        // Link seguro das cobranças da XPLENDOR: o mesmo padrão, por IP e por token (em hash).
        \Illuminate\Support\Facades\RateLimiter::for('charge-public-read', fn (\Illuminate\Http\Request $r) => \Illuminate\Cache\RateLimiting\Limit::perMinute(60)->by('cr|' . $r->ip()));
        \Illuminate\Support\Facades\RateLimiter::for('charge-public-open', fn (\Illuminate\Http\Request $r) => \Illuminate\Cache\RateLimiting\Limit::perMinute(30)->by('co|' . $r->ip()));
        \Illuminate\Support\Facades\RateLimiter::for('charge-public-action', fn (\Illuminate\Http\Request $r) => [
            \Illuminate\Cache\RateLimiting\Limit::perMinute(10)->by('ca|' . $r->ip()),
            \Illuminate\Cache\RateLimiting\Limit::perHour(10)->by('ct|' . hash('sha256', (string) $r->header('X-Charge-Token', ''))),
        ]);
        // Link de aprovação de conteúdos (F3c): o mesmo padrão, por IP e por token (em hash).
        \Illuminate\Support\Facades\RateLimiter::for('review-public-read', fn (\Illuminate\Http\Request $r) => \Illuminate\Cache\RateLimiting\Limit::perMinute(60)->by('rr|' . $r->ip()));
        \Illuminate\Support\Facades\RateLimiter::for('review-public-open', fn (\Illuminate\Http\Request $r) => \Illuminate\Cache\RateLimiting\Limit::perMinute(30)->by('ro|' . $r->ip()));
        \Illuminate\Support\Facades\RateLimiter::for('review-public-action', fn (\Illuminate\Http\Request $r) => [
            \Illuminate\Cache\RateLimiting\Limit::perMinute(30)->by('ra|' . $r->ip()),
            \Illuminate\Cache\RateLimiting\Limit::perHour(200)->by('rt|' . hash('sha256', (string) $r->header('X-Review-Token'))),
        ]);
    }
}
