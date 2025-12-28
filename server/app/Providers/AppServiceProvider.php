<?php

namespace App\Providers;

use App\Models\{
    Company
};
use App\Observers\{
    CompanyObserver
};
use App\Repositories\Contracts\{
    CarBrandRepositoryInterface,
    CarModelRepositoryInterface,
    CarRepositoryInterface,
    CompanyRepositoryInterface,
    DistrictRepositoryInterface,
    MunicipalityRepositoryInterface,
    ParishRepositoryInterface,
    PlanRepositoryInterface,
    UserInviteRepositoryInterface,
    UserRepositoryInterface
};
use App\Repositories\{
    CarBrandRepository,
    CarModelRepository,
    CarRepository,
    CompanyRepository,
    DistrictRepository,
    MunicipalitytRepository,
    ParishRepository,
    PlanRepository,
    UserInviteRepository,
    UserRepository
};
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(CarRepositoryInterface::class, CarRepository::class);
        $this->app->bind(CarBrandRepositoryInterface::class, CarBrandRepository::class);
        $this->app->bind(CarModelRepositoryInterface::class, CarModelRepository::class);
        $this->app->bind(CompanyRepositoryInterface::class, CompanyRepository::class);
        $this->app->bind(DistrictRepositoryInterface::class, DistrictRepository::class);
        $this->app->bind(MunicipalityRepositoryInterface::class, MunicipalitytRepository::class);
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
    }
}
