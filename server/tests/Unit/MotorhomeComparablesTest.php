<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Car;
use App\Models\CarBrand;
use App\Models\CarCategory;
use App\Models\CarModel;
use App\Repositories\CarMarketSnapshotRepository;
use App\Repositories\Contracts\CarMarketSnapshotRepositoryInterface;
use App\Services\MarketSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MotorhomeComparablesTest extends TestCase
{
    use RefreshDatabase;

    private function makeService(): MarketSnapshotService
    {
        return new MarketSnapshotService(
            $this->app->make(CarMarketSnapshotRepositoryInterface::class)
        );
    }

    private function seedSnapshot(array $extra): void
    {
        $repo = $this->app->make(CarMarketSnapshotRepository::class);
        $repo->upsertSnapshots([array_merge([
            'external_id'    => 'sv-' . uniqid('', true),
            'source'         => 'standvirtual',
            'vehicle_type'   => 'motorhome',
            'title'          => 'Test listing',
            'url'            => 'https://standvirtual.com/anuncio/' . rand(1, 99999),
            'brand'          => 'McLouis',
            'model'          => 'Yearling',
            'year'           => 2019,
            'price'          => 55000.00,
            'price_currency' => 'EUR',
            'fuel'           => 'diesel',
            'gearbox'        => 'manual',
            'scraped_at'     => now()->toDateTimeString(),
            'created_at'     => now()->toDateTimeString(),
            'updated_at'     => now()->toDateTimeString(),
        ], $extra)]);
    }

    private function makeCar(
        string $vehicleType,
        string $brand,
        string $model,
        int $year,
        float $priceGross,
        ?float $promo = null,
    ): Car {
        $car = new Car();
        $car->forceFill([
            'vehicle_type'      => $vehicleType,
            'registration_year' => $year,
            'price_gross'       => $priceGross,
            'promo_price_gross' => $promo,
        ]);

        $brandModel = new CarBrand();
        $brandModel->forceFill(['name' => $brand]);
        $car->setRelation('brand', $brandModel);

        $modelModel = new CarModel();
        $modelModel->forceFill(['name' => $model]);
        $car->setRelation('model', $modelModel);

        return $car;
    }

    // -------------------------------------------------------------------------
    // New repository method: brand + price band + year, ignoring model
    // -------------------------------------------------------------------------

    public function test_byBrandPrice_filters_by_brand_price_and_year_ignoring_model(): void
    {
        // In band (price 43.5k–72.5k, year 2014–2024), different models — should match
        $this->seedSnapshot(['external_id' => 'in-1', 'brand' => 'McLouis', 'model' => 'Yearling', 'year' => 2019, 'price' => 50000]);
        $this->seedSnapshot(['external_id' => 'in-2', 'brand' => 'McLouis', 'model' => 'Nevis',    'year' => 2020, 'price' => 60000]);
        // Out — price too high
        $this->seedSnapshot(['external_id' => 'out-price', 'brand' => 'McLouis', 'model' => 'Tandy', 'year' => 2019, 'price' => 120000]);
        // Out — different brand
        $this->seedSnapshot(['external_id' => 'out-brand', 'brand' => 'Adria', 'model' => 'Matrix', 'year' => 2019, 'price' => 55000]);
        // Out — year outside window
        $this->seedSnapshot(['external_id' => 'out-year', 'brand' => 'McLouis', 'model' => 'Menfys', 'year' => 2000, 'price' => 55000]);

        $repo = $this->app->make(CarMarketSnapshotRepository::class);
        $car  = $this->makeCar('motorhome', 'McLouis', 'Menfys Van', 2019, 58000);

        // ±25% of 58000 = [43500, 72500]; year ±5 = [2014, 2024]
        $result = $repo->getComparableSnapshotsByBrandPrice($car, 5, 43500.0, 72500.0);

        $ids = $result->pluck('external_id')->all();
        $this->assertContains('in-1', $ids);
        $this->assertContains('in-2', $ids);
        $this->assertNotContains('out-price', $ids);
        $this->assertNotContains('out-brand', $ids);
        $this->assertNotContains('out-year', $ids);
        $this->assertCount(2, $result);
    }

    public function test_byBrandPrice_returns_empty_without_brand_or_year(): void
    {
        $repo = $this->app->make(CarMarketSnapshotRepository::class);

        $noBrand = new Car();
        $noBrand->forceFill(['vehicle_type' => 'motorhome', 'registration_year' => 2019]);
        $noBrand->setRelation('brand', null);

        $this->assertTrue($repo->getComparableSnapshotsByBrandPrice($noBrand, 5, 1.0, 9999999.0)->isEmpty());
    }

    // -------------------------------------------------------------------------
    // Cascade: motorhome falls back to brand+price when exact model gives zero
    // -------------------------------------------------------------------------

    public function test_getComparables_motorhome_falls_back_to_brand_price(): void
    {
        // Same brand, different models (NOT "Menfys Van"), within price band + year
        $this->seedSnapshot(['external_id' => 'mc-1', 'brand' => 'McLouis', 'model' => 'Yearling', 'year' => 2019, 'price' => 52000, 'fuel' => 'diesel']);
        $this->seedSnapshot(['external_id' => 'mc-2', 'brand' => 'McLouis', 'model' => 'Nevis',    'year' => 2018, 'price' => 60000, 'fuel' => 'diesel']);

        $car = $this->makeCar('motorhome', 'McLouis', 'Menfys Van', 2019, 58000);
        $car->forceFill(['fuel_type' => 'Diesel', 'transmission' => 'Manual', 'power_hp' => 130]);

        $result = $this->makeService()->getComparables($car);

        $this->assertTrue($result['fallback_used']);
        $this->assertGreaterThanOrEqual(1, $result['snapshots']->count());
    }

    // -------------------------------------------------------------------------
    // Regression: cars do NOT use the brand+price fallback (model exact only)
    // -------------------------------------------------------------------------

    private function withCategory(Car $car, string $slug): Car
    {
        $cat = new CarCategory();
        $cat->forceFill(['slug' => $slug, 'name' => ucfirst($slug)]);
        $car->setRelation('category', $cat);
        return $car;
    }

    // -------------------------------------------------------------------------
    // New degree: motorhome + category (body_type) + year
    // -------------------------------------------------------------------------

    public function test_getComparables_motorhome_uses_category_when_available(): void
    {
        // Snapshots no body_type 'furgao' (Standvirtual slug para campervan).
        $this->seedSnapshot(['external_id' => 'fv-1', 'category' => 'furgao', 'brand' => 'Adria', 'model' => 'Twin', 'year' => 2019, 'price' => 56000]);
        $this->seedSnapshot(['external_id' => 'fv-2', 'category' => 'furgao', 'brand' => 'Pössl', 'model' => 'Roadcar', 'year' => 2020, 'price' => 60000]);
        // Outras categorias — não devem entrar.
        $this->seedSnapshot(['external_id' => 'int-1', 'category' => 'integral', 'brand' => 'McLouis', 'model' => 'Nevis', 'year' => 2019, 'price' => 95000]);

        $car = $this->withCategory(
            $this->makeCar('motorhome', 'McLouis', 'Menfys Van', 2019, 58000),
            'campervan',
        );

        $result = $this->makeService()->getComparables($car);

        $ids = $result['snapshots']->pluck('external_id')->all();
        $this->assertTrue($result['fallback_used']);
        $this->assertContains('fv-1', $ids);
        $this->assertContains('fv-2', $ids);
        $this->assertNotContains('int-1', $ids);
    }

    public function test_getComparables_motorhome_falls_back_to_brand_price_when_category_empty(): void
    {
        // Sem snapshots da mesma categoria, mas a marca tem snapshots dentro
        // da faixa de preço → cai para o degrau brand+price (Attempt 5).
        $this->seedSnapshot(['external_id' => 'mc-1', 'brand' => 'McLouis', 'model' => 'Yearling', 'year' => 2019, 'price' => 55000, 'category' => 'perfiladas']);
        $this->seedSnapshot(['external_id' => 'mc-2', 'brand' => 'McLouis', 'model' => 'Nevis',    'year' => 2018, 'price' => 60000, 'category' => 'perfiladas']);

        $car = $this->withCategory(
            $this->makeCar('motorhome', 'McLouis', 'Menfys Van', 2019, 58000),
            'campervan',
        );
        $car->forceFill(['fuel_type' => 'Diesel']);

        $result = $this->makeService()->getComparables($car);

        $this->assertTrue($result['fallback_used']);
        $this->assertGreaterThanOrEqual(1, $result['snapshots']->count());
        // Veio do brand+price (Attempt 5), não do category (Attempt 4).
        $this->assertContains('mc-1', $result['snapshots']->pluck('external_id')->all());
    }

    public function test_getComparables_car_does_not_use_brand_price_fallback(): void
    {
        // BMW snapshots, different model than the target car, within price band
        $this->seedSnapshot(['external_id' => 'bmw-1', 'vehicle_type' => 'car', 'brand' => 'BMW', 'model' => '320d', 'year' => 2020, 'price' => 30000, 'fuel' => 'diesel']);
        $this->seedSnapshot(['external_id' => 'bmw-2', 'vehicle_type' => 'car', 'brand' => 'BMW', 'model' => '520d', 'year' => 2020, 'price' => 32000, 'fuel' => 'diesel']);

        // Car model "M3" not present in snapshots
        $car = $this->makeCar('car', 'BMW', 'M3', 2020, 31000);
        $car->forceFill(['fuel_type' => 'Diesel']);

        $result = $this->makeService()->getComparables($car);

        $this->assertFalse($result['fallback_used']);
        $this->assertTrue($result['snapshots']->isEmpty());
    }

    // -------------------------------------------------------------------------
    // MS1.c — Guard: degrau 5 (brand+price) saltado quando preço efectivo <= 0
    // -------------------------------------------------------------------------

    public function test_getComparables_motorhome_skips_brand_price_when_effective_price_is_zero(): void
    {
        // Snapshots que NÃO bateriam nos degraus 1-3 (modelo diferente, sem
        // categoria) — só o degrau 5 (brand+price) os poderia apanhar. Mas
        // com preço efectivo 0, a faixa degenera em [0, 0] e o degrau salta
        // sem chamar o repo (e sem dividir por zero).
        $this->seedSnapshot(['external_id' => 'mc-1', 'brand' => 'McLouis', 'model' => 'Yearling', 'year' => 2019, 'price' => 55000, 'fuel' => 'diesel']);

        // Preço gross 0 + sem promo → effectivePrice() == 0.0
        $car = $this->makeCar('motorhome', 'McLouis', 'Menfys Van', 2019, 0.0);
        $car->forceFill(['fuel_type' => 'Diesel']);

        $result = $this->makeService()->getComparables($car);

        // Degrau 5 saltado → snapshots vazios + fallback_used == false.
        $this->assertFalse($result['fallback_used']);
        $this->assertTrue($result['snapshots']->isEmpty());
    }

    public function test_getComparables_motorhome_uses_brand_price_when_effective_price_positive(): void
    {
        // Regressão do guard: preço > 0 → degrau 5 corre como hoje.
        $this->seedSnapshot(['external_id' => 'mc-1', 'brand' => 'McLouis', 'model' => 'Yearling', 'year' => 2019, 'price' => 55000, 'fuel' => 'diesel']);

        $car = $this->makeCar('motorhome', 'McLouis', 'Menfys Van', 2019, 58000.0);
        $car->forceFill(['fuel_type' => 'Diesel']);

        $result = $this->makeService()->getComparables($car);

        $this->assertTrue($result['fallback_used']);
        $this->assertGreaterThanOrEqual(1, $result['snapshots']->count());
    }

    // -------------------------------------------------------------------------
}
