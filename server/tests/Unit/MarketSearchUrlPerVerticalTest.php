<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Car;
use App\Models\CarBrand;
use App\Repositories\Contracts\CarMarketSnapshotRepositoryInterface;
use App\Services\MarketSnapshotService;
use ReflectionClass;
use Tests\TestCase;

/**
 * MS1.a — slugs de combustível por vertical do Standvirtual.
 *
 * O Standvirtual usa dicionários diferentes por secção:
 *   /carros        — slugs herdados de OLX/Otomoto (gaz, lpg, plugin-hybrid)
 *   /autocaravanas — slugs em português (gasolina)
 *
 * Validação empírica (curl ao live, 2026-06-10):
 *   /autocaravanas?fuel=gasolina → 2 anúncios
 *   /autocaravanas?fuel=diesel   → 321 anúncios
 *   /autocaravanas?fuel=gaz      → 0 anúncios (controlo negativo)
 *   /carros?fuel=gaz             → 984 anúncios (regressão)
 *
 * Slugs sem mapeamento validado → OMITIR o parâmetro (omissão > slug errado,
 * que zera silenciosamente). Reflection é usado para invocar buildSearchUrl
 * (privado) sem ter de tocar em BD nem expor a API.
 */
class MarketSearchUrlPerVerticalTest extends TestCase
{
    private function makeService(): MarketSnapshotService
    {
        return new MarketSnapshotService(
            $this->app->make(CarMarketSnapshotRepositoryInterface::class)
        );
    }

    private function makeCar(string $vehicleType, ?string $fuel, ?int $year = 2020, string $brandName = 'Carado'): Car
    {
        $brand = new CarBrand();
        $brand->forceFill(['name' => $brandName]);

        $car = new Car();
        $car->forceFill([
            'vehicle_type'      => $vehicleType,
            'fuel_type'         => $fuel,
            'registration_year' => $year,
        ]);
        $car->setRelation('brand', $brand);

        return $car;
    }

    private function buildSearchUrl(Car $car): string
    {
        $service = $this->makeService();
        $ref     = new ReflectionClass($service);
        $method  = $ref->getMethod('buildSearchUrl');
        $method->setAccessible(true);

        return $method->invoke($service, $car);
    }

    // ── /autocaravanas — slugs validados ──────────────────────────────────

    public function test_motorhome_with_gasolina_uses_gasolina_slug(): void
    {
        $url = $this->buildSearchUrl($this->makeCar('motorhome', 'gasolina'));

        $this->assertStringContainsString('/autocaravanas/carado/desde-2019', $url);
        $this->assertStringContainsString('filter_enum_fuel_type%5D=gasolina', $url);
    }

    public function test_motorhome_with_diesel_uses_diesel_slug(): void
    {
        $url = $this->buildSearchUrl($this->makeCar('motorhome', 'diesel'));

        $this->assertStringContainsString('filter_enum_fuel_type%5D=diesel', $url);
    }

    public function test_motorhome_with_petrol_aliased_to_gasolina(): void
    {
        $url = $this->buildSearchUrl($this->makeCar('motorhome', 'petrol'));

        $this->assertStringContainsString('filter_enum_fuel_type%5D=gasolina', $url);
    }

    // ── /autocaravanas — slugs NÃO validados → omitir ─────────────────────

    public function test_motorhome_with_lpg_omits_fuel_param(): void
    {
        $url = $this->buildSearchUrl($this->makeCar('motorhome', 'lpg'));

        $this->assertStringNotContainsString('filter_enum_fuel_type', $url);
        // year_to ainda lá → URL não fica vazio
        $this->assertStringContainsString('first_registration_year', $url);
    }

    public function test_motorhome_with_electric_omits_fuel_param(): void
    {
        $url = $this->buildSearchUrl($this->makeCar('motorhome', 'electric'));
        $this->assertStringNotContainsString('filter_enum_fuel_type', $url);
    }

    public function test_motorhome_with_hybrid_omits_fuel_param(): void
    {
        $url = $this->buildSearchUrl($this->makeCar('motorhome', 'hybrid'));
        $this->assertStringNotContainsString('filter_enum_fuel_type', $url);
    }

    public function test_motorhome_with_gaz_omits_fuel_param(): void
    {
        // Controlo negativo: gaz é slug de /carros e dá 0 anúncios em /autocaravanas.
        $url = $this->buildSearchUrl($this->makeCar('motorhome', 'gaz'));
        $this->assertStringNotContainsString('filter_enum_fuel_type', $url);
    }

    // ── /carros — regressão (mapa antigo continua a funcionar) ────────────

    public function test_car_with_gasolina_still_uses_gaz(): void
    {
        $url = $this->buildSearchUrl($this->makeCar('car', 'gasolina', 2020, 'BMW'));

        $this->assertStringContainsString('/carros/bmw/desde-2019', $url);
        $this->assertStringContainsString('filter_enum_fuel_type%5D=gaz', $url);
    }

    public function test_car_with_lpg_uses_gpl(): void
    {
        $url = $this->buildSearchUrl($this->makeCar('car', 'lpg'));
        $this->assertStringContainsString('filter_enum_fuel_type%5D=gpl', $url);
    }

    public function test_car_with_plugin_hybrid_uses_plugin_hybrid(): void
    {
        $url = $this->buildSearchUrl($this->makeCar('car', 'plug-in-hybrid'));
        $this->assertStringContainsString('filter_enum_fuel_type%5D=plugin-hybrid', $url);
    }

    public function test_car_with_hybrid_uses_hibride_gaz(): void
    {
        $url = $this->buildSearchUrl($this->makeCar('car', 'hybrid'));
        $this->assertStringContainsString('filter_enum_fuel_type%5D=hibride-gaz', $url);
    }

    // ── Edge cases ────────────────────────────────────────────────────────

    public function test_car_without_fuel_omits_fuel_param(): void
    {
        $url = $this->buildSearchUrl($this->makeCar('car', null));
        $this->assertStringNotContainsString('filter_enum_fuel_type', $url);
    }

    public function test_motorhome_without_fuel_omits_fuel_param(): void
    {
        $url = $this->buildSearchUrl($this->makeCar('motorhome', null));
        $this->assertStringNotContainsString('filter_enum_fuel_type', $url);
    }

    public function test_unknown_vehicle_type_defaults_to_car_path(): void
    {
        // ?? 'car' no buildSearchUrl é a rede de segurança.
        $car = $this->makeCar('motorbike', 'gasolina');
        $car->forceFill(['vehicle_type' => null]);
        $car->setRelation('brand', $car->brand);

        $url = $this->buildSearchUrl($car);
        $this->assertStringContainsString('/carros/', $url);
        // Em /carros, gasolina → gaz
        $this->assertStringContainsString('filter_enum_fuel_type%5D=gaz', $url);
    }
}
