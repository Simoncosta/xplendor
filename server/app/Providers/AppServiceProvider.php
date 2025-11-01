<?php

namespace App\Providers;

use App\Models\Car;
use App\Models\Company;
use App\Observers\CarObserver;
use App\Observers\CompanyObserver;
use App\Repositories\Contracts\{
    CarLeadRepositoryInterface,
    CarLogRepositoryInterface,
    CarRepositoryInterface,
    CarViewRepositoryInterface,
    CompanyOperationRepositoryInterface,
    CompanyRepositoryInterface,
    CompanyViewRepositoryInterface,
    PlanRepositoryInterface,
    UserRepositoryInterface
};
use App\Repositories\{
    CarLeadRepository,
    CarLogRepository,
    CarRepository,
    CarViewRepository,
    CompanyOperationRepository,
    CompanyRepository,
    CompanyViewRepository,
    PlanRepository,
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
        $this->app->bind(CarLeadRepositoryInterface::class, CarLeadRepository::class);
        $this->app->bind(CarLogRepositoryInterface::class, CarLogRepository::class);
        $this->app->bind(CarRepositoryInterface::class, CarRepository::class);
        $this->app->bind(CarViewRepositoryInterface::class, CarViewRepository::class);
        $this->app->bind(CompanyOperationRepositoryInterface::class, CompanyOperationRepository::class);
        $this->app->bind(CompanyRepositoryInterface::class, CompanyRepository::class);
        $this->app->bind(CompanyViewRepositoryInterface::class, CompanyViewRepository::class);
        $this->app->bind(PlanRepositoryInterface::class, PlanRepository::class);
        $this->app->bind(UserRepositoryInterface::class, UserRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Company::observe(CompanyObserver::class);
        Car::observe(CarObserver::class);
    }
}
