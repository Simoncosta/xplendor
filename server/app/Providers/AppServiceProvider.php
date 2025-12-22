<?php

namespace App\Providers;

use App\Models\Car;
use App\Models\Company;
use App\Observers\CarObserver;
use App\Observers\CompanyObserver;
use App\Repositories\Contracts\{
    CompanyRepositoryInterface,
    DistrictRepositoryInterface,
    MunicipalityRepositoryInterface,
    ParishRepositoryInterface,
    PlanRepositoryInterface,
    UserInviteRepositoryInterface,
    UserRepositoryInterface
};
use App\Repositories\{
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
    public function boot(): void {}
}
