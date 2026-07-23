<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Resources\Public\CarPublicResource;
use App\Models\Car;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Unit test for CarPublicResource engine fields exposure.
 *
 * Isolated from DB (uses Car model in-memory) — does NOT depend on the
 * dev MariaDB workaround that CarControllerTest carries (see dívida 46).
 *
 * Guards against regression of the 2026-07-23 addition of `engine_brand`
 * and confirms `engine_capacity_cc` was already being emitted.
 */
class CarPublicResourceEngineFieldsTest extends TestCase
{
    private function makeCarInMemory(array $attributes = []): Car
    {
        $car = new Car();
        $car->forceFill(array_merge([
            'id'                 => 999,
            'vehicle_type'       => 'motorhome',
            'status'             => 'active',
            'condition'          => 'used',
            'origin'             => 'national',
            'registration_year'  => 2019,
            'registration_month' => 6,
            'mileage_km'         => 42000,
            'fuel_type'          => 'diesel',
            'transmission'       => 'manual',
            'power_hp'           => 140,
            'engine_capacity_cc' => 1995,
            'engine_brand'       => 'Fiat',
            'cylinders'          => 4,
            'doors'              => 4,
            'seats'              => 4,
            'price_gross'        => 45000,
            'exterior_color'     => 'Branco',
        ], $attributes));

        // Pre-load empty relations so Resource does not hit DB.
        $car->setRelation('vehicleAttribute', null);
        $car->setRelation('brand', null);
        $car->setRelation('model', null);
        $car->setRelation('category', null);

        return $car;
    }

    public function test_public_resource_emits_engine_brand(): void
    {
        $car = $this->makeCarInMemory(['engine_brand' => 'Fiat']);

        $payload = (new CarPublicResource($car))->toArray(Request::create('/'));

        $this->assertArrayHasKey('engine_brand', $payload);
        $this->assertSame('Fiat', $payload['engine_brand']);
    }

    public function test_public_resource_still_emits_engine_capacity_cc(): void
    {
        // Regression guard — cliente reportou "não aparece cilindrada".
        // Já sai desde sempre; se este teste falhar, é porque alguém removeu.
        $car = $this->makeCarInMemory(['engine_capacity_cc' => 1995]);

        $payload = (new CarPublicResource($car))->toArray(Request::create('/'));

        $this->assertArrayHasKey('engine_capacity_cc', $payload);
        $this->assertSame(1995, $payload['engine_capacity_cc']);
    }

    public function test_public_resource_engine_brand_is_null_when_not_set(): void
    {
        $car = $this->makeCarInMemory(['engine_brand' => null]);

        $payload = (new CarPublicResource($car))->toArray(Request::create('/'));

        $this->assertArrayHasKey('engine_brand', $payload);
        $this->assertNull($payload['engine_brand']);
    }

    public function test_public_resource_does_not_expose_sensitive_fields(): void
    {
        // Defensive assertion — the addition of engine_brand must NOT
        // accidentally open a PII leak.
        $car = $this->makeCarInMemory([
            'vin'            => 'WVWZZZ1KZ8W123456',
            'license_plate'  => 'AA-00-BB',
            'internal_notes' => 'stand internal note',
        ]);

        $payload = (new CarPublicResource($car))->toArray(Request::create('/'));

        $this->assertArrayNotHasKey('vin', $payload);
        $this->assertArrayNotHasKey('license_plate', $payload);
        $this->assertArrayNotHasKey('internal_notes', $payload);
    }
}
