<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Resources\CarPrintSheetResource;
use App\Http\Resources\Public\CarPublicResource;
use App\Models\Car;
use App\Models\VehicleAttribute;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Exposição na API pública dos campos de habitação que faltavam (Sala, sleeps,
 * parciais de Energia/Chassis/Exterior/Interior) + garantia de que a inspeção
 * interna (infiltrações) NUNCA vaza — nem na API pública nem na ficha A4.
 * In-memory (sem DB), como CarPublicResourceEngineFieldsTest.
 */
class CarPublicResourceHabitationExposureTest extends TestCase
{
    private function makeCar(array $attributes): Car
    {
        $car = new Car();
        $car->forceFill([
            'id' => 999, 'vehicle_type' => 'motorhome', 'status' => 'active',
            'seats' => 4, 'price_gross' => 45000,
        ]);

        $va = new VehicleAttribute();
        $va->forceFill(['attributes' => $attributes]);

        $car->setRelation('vehicleAttribute', $va);
        $car->setRelation('brand', null);
        $car->setRelation('model', null);
        $car->setRelation('category', null);

        return $car;
    }

    private const RICH_ATTRS = [
        'habitation_basics' => ['sleeps' => 4, 'has_kitchen' => true],
        'living_room'       => ['layout' => 'face_to_face', 'has_extending_table' => true],
        'energy_climate'    => [
            'has_solar_panel' => true, 'solar_panel_count' => 2, 'solar_panel_watts' => 300,
            'lithium_battery_ah' => 200, 'lithium_battery_count' => 1,
            'inverter_type' => 'pure_sine', 'inverter_watts' => 2000,
            'has_aircon_220v' => true, 'has_viesa' => true, 'gpl_bottles_count' => 2,
            'has_battery_cutoff' => true, 'cabin_battery_count' => 1, 'cell_battery_count' => 2,
        ],
        'chassis_structure' => [
            'has_air_suspension' => true, 'has_air_suspension_compressor' => true,
            'has_dual_rear_wheel' => true, 'chassis_notes' => 'Reforço traseiro',
        ],
        'exterior' => [
            'has_external_ladder' => true, 'has_fix_n_go_kit' => true,
            'garage' => ['has_garage' => true, 'is_spacious' => true, 'has_double_opening' => false, 'has_height_adjuster' => false],
        ],
        'interior_furniture' => [
            'has_wardrobe' => true, 'tv_count' => 2, 'tv_location' => 'sala',
            // Inspeção interna — NÃO deve sair:
            'has_water_infiltrations' => true, 'infiltrations_notes' => 'Fuga no teto',
        ],
    ];

    public function test_public_api_exposes_new_habitation_fields(): void
    {
        $car = $this->makeCar(self::RICH_ATTRS);
        $p = (new CarPublicResource($car))->toArray(Request::create('/'));

        // specs.sleeps
        $this->assertSame(4, $p['specs']['sleeps']);

        $f = $p['features'];
        // Sala
        $this->assertSame('face_to_face', $f['living_room_layout']);
        $this->assertTrue($f['has_extending_table']);
        // Energia (parciais)
        $this->assertSame(2, $f['solar_panel_count']);
        $this->assertSame(300, $f['solar_panel_watts']);
        $this->assertSame(200, $f['lithium_battery_ah']);
        $this->assertSame(1, $f['lithium_battery_count']);
        $this->assertSame('pure_sine', $f['inverter_type']);
        $this->assertSame(2000, $f['inverter_watts']);
        $this->assertTrue($f['has_aircon_220v']);
        $this->assertTrue($f['has_viesa']);
        $this->assertSame(2, $f['gpl_bottles_count']);
        $this->assertTrue($f['has_battery_cutoff']);
        $this->assertSame(1, $f['cabin_battery_count']);
        $this->assertSame(2, $f['cell_battery_count']);
        // Chassis
        $this->assertTrue($f['has_air_suspension']);
        $this->assertTrue($f['has_air_suspension_compressor']);
        $this->assertTrue($f['has_dual_rear_wheel']);
        $this->assertSame('Reforço traseiro', $f['chassis_notes']);
        // Exterior
        $this->assertTrue($f['has_external_ladder']);
        $this->assertTrue($f['has_fix_n_go_kit']);
        $this->assertTrue($f['garage']['has_garage']);
        $this->assertTrue($f['garage']['is_spacious']);
        // Interior
        $this->assertTrue($f['has_wardrobe']);
        $this->assertSame(2, $f['tv_count']);
        $this->assertSame('sala', $f['tv_location']);
    }

    public function test_public_api_never_exposes_internal_inspection(): void
    {
        $car = $this->makeCar(self::RICH_ATTRS);
        $p = (new CarPublicResource($car))->toArray(Request::create('/'));

        $this->assertArrayNotHasKey('has_water_infiltrations', $p['features']);
        $this->assertArrayNotHasKey('infiltrations_notes', $p['features']);
        // Garantia extra: nem no JSON serializado inteiro.
        $this->assertStringNotContainsString('infiltration', json_encode($p));
    }

    public function test_a4_resource_strips_internal_inspection(): void
    {
        $car = $this->makeCar(self::RICH_ATTRS);
        $car->setRelation('company', null);

        $p = (new CarPrintSheetResource($car))->toArray(Request::create('/'));

        $inf = $p['vehicle_attributes']['interior_furniture'] ?? [];
        $this->assertArrayNotHasKey('has_water_infiltrations', $inf);
        $this->assertArrayNotHasKey('infiltrations_notes', $inf);
        // Mas os campos legítimos da A4 mantêm-se.
        $this->assertTrue($inf['has_wardrobe'] ?? false);
        $this->assertSame(4, $p['headline_stats']['sleeps']);
    }
}
