<?php

namespace App\Providers;

use App\Models\{
    Blog,
    CarImage,
    CarLead,
    CarPerformanceMetric,
    CarSalePotentialScore,
    Company
};
use App\Observers\{
    BlogObserver,
    CarImageObserver,
    CarPerformanceMetricObserver,
    CompanyObserver,
    LeadObserver
};
use App\Repositories\Contracts\{
    BlogRepositoryInterface,
    CarAiAnalysesRepositoryInterface,
    CarBrandRepositoryInterface,
    CarInteractionRepositoryInterface,
    CarLeadRepositoryInterface,
    CarMarketingIdeaRepositoryInterface,
    CarMarketingRoiRepositoryInterface,
    CompanyIntegrationRepositoryInterface,
    CarmineConnectionRepositoryInterface,
    CarModelRepositoryInterface,
    CarPerformanceMetricRepositoryInterface,
    CarRepositoryInterface,
    CarSalePotentialScoreRepositoryInterface,
    CarViewRepositoryInterface,
    CompanyRepositoryInterface,
    DashboardRepositoryInterface,
    DistrictRepositoryInterface,
    MunicipalityRepositoryInterface,
    NewsletterRepositoryInterface,
    ParishRepositoryInterface,
    PlanRepositoryInterface,
    UserInviteRepositoryInterface,
    UserRepositoryInterface
};
use App\Repositories\{
    BlogRepository,
    CarAiAnalysesRepository,
    CarBrandRepository,
    CarInteractionRepository,
    CarLeadRepository,
    CarMarketingIdeaRepository,
    CarMarketingRoiRepository,
    CompanyIntegrationRepository,
    CarmineConnectionRepository,
    CarModelRepository,
    CarPerformanceMetricRepository,
    CarRepository,
    CarSalePotentialScoreRepository,
    CarViewRepository,
    CompanyRepository,
    DashboardRepository,
    DistrictRepository,
    MunicipalityRepository,
    NewsletterRepository,
    ParishRepository,
    PlanRepository,
    UserInviteRepository,
    UserRepository
};
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use App\Http\Middleware\CheckCompanyApiToken;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(BlogRepositoryInterface::class, BlogRepository::class);
        $this->app->bind(CarRepositoryInterface::class, CarRepository::class);
        $this->app->bind(CarAiAnalysesRepositoryInterface::class, CarAiAnalysesRepository::class);
        $this->app->bind(CarBrandRepositoryInterface::class, CarBrandRepository::class);
        $this->app->bind(CarInteractionRepositoryInterface::class, CarInteractionRepository::class);
        $this->app->bind(CarLeadRepositoryInterface::class, CarLeadRepository::class);
        $this->app->bind(CarMarketingIdeaRepositoryInterface::class, CarMarketingIdeaRepository::class);
        $this->app->bind(CarMarketingRoiRepositoryInterface::class, CarMarketingRoiRepository::class);
        $this->app->bind(CompanyIntegrationRepositoryInterface::class, CompanyIntegrationRepository::class);
        $this->app->bind(CarModelRepositoryInterface::class, CarModelRepository::class);
        $this->app->bind(CarPerformanceMetricRepositoryInterface::class, CarPerformanceMetricRepository::class);
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
        $this->app->bind(UserRepositoryInterface::class, UserRepository::class);
        $this->app->bind(UserInviteRepositoryInterface::class, UserInviteRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Company::observe(CompanyObserver::class);
        Blog::observe(BlogObserver::class);
        CarLead::observe(LeadObserver::class);
        CarPerformanceMetric::observe(CarPerformanceMetricObserver::class);
        CarImage::observe(CarImageObserver::class);

        Route::aliasMiddleware('check_company_api_token', CheckCompanyApiToken::class);
    }
}
