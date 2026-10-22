<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ScrapeMarketSnapshotJob;
use App\Models\Car;
use App\Models\CarBrand;
use App\Models\CarModel;
use App\Models\Company;
use App\Support\ExternalCalls;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Guarda única das chamadas externas: segue o interruptor do agendador e trava o observador
 * que pedia a recolha no Standvirtual ao criar uma viatura (o incidente no dev).
 */
class ExternalCallsGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_guard_follows_the_switch(): void
    {
        config(['scheduler.external_jobs' => false]);
        $this->assertFalse(ExternalCalls::allowed());
        $this->assertFalse(ExternalCalls::allowed(1));

        config(['scheduler.external_jobs' => true]);
        $this->assertTrue(ExternalCalls::allowed());
        $this->assertTrue(ExternalCalls::allowed(1));
    }

    public function test_creating_a_car_only_asks_the_standvirtual_with_the_switch_on(): void
    {
        Queue::fake();
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $company = Company::create(['nipc' => '500000950', 'fiscal_name' => 'Stand Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $brand = CarBrand::create(['name' => 'Fiat', 'slug' => 'fiat', 'vehicle_type' => 'car']);
        $model = CarModel::create(['name' => 'Tipo', 'car_brand_id' => $brand->id]);
        $car = fn () => Car::factory()->create(['company_id' => $company->id, 'car_brand_id' => $brand->id, 'car_model_id' => $model->id,
            'vehicle_type' => 'car', 'registration_year' => 2021, 'price_gross' => 25000]);

        // O observador salta sempre nos testes; aqui simula-se o dev para chegar à guarda.
        $this->app['env'] = 'local';
        try {
            config(['scheduler.external_jobs' => false]);
            $car();
            Queue::assertNotPushed(ScrapeMarketSnapshotJob::class);

            config(['scheduler.external_jobs' => true]);
            $car();
            Queue::assertPushed(ScrapeMarketSnapshotJob::class, 1);
        } finally {
            $this->app['env'] = 'testing';
        }
    }
}
