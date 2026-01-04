<?php

namespace App\Providers;

use App\Models\{
    Blog,
    Company
};
use App\Observers\{
    BlogObserver,
    CompanyObserver
};
use App\Repositories\Contracts\{
    BlogRepositoryInterface,
    CarAiAnalysesRepositoryInterface,
    CarBrandRepositoryInterface,
    CarLeadRepositoryInterface,
    CarModelRepositoryInterface,
    CarRepositoryInterface,
    CarViewRepositoryInterface,
    CompanyRepositoryInterface,
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
    CarLeadRepository,
    CarModelRepository,
    CarRepository,
    CarViewRepository,
    CompanyRepository,
    DistrictRepository,
    MunicipalitytRepository,
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
        $this->app->bind(CarLeadRepositoryInterface::class, CarLeadRepository::class);
        $this->app->bind(CarModelRepositoryInterface::class, CarModelRepository::class);
        $this->app->bind(CarViewRepositoryInterface::class, CarViewRepository::class);
        $this->app->bind(CompanyRepositoryInterface::class, CompanyRepository::class);
        $this->app->bind(DistrictRepositoryInterface::class, DistrictRepository::class);
        $this->app->bind(MunicipalityRepositoryInterface::class, MunicipalitytRepository::class);
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

        Route::aliasMiddleware('check_company_api_token', CheckCompanyApiToken::class);
    }
}
