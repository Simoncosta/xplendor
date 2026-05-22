<?php

namespace Tests\Unit;

use App\Services\CarService;
use ReflectionClass;
use Tests\TestCase;

class CarServiceVehicleAttributesTest extends TestCase
{
    public function test_it_normalizes_motorhome_vehicle_attributes_b1_format(): void
    {
        $normalized = $this->normalizeVehicleAttributes([
            'dimensions' => [
                'length_m' => '6.5',
                'width_m'  => '2.2',
                'height_m' => '2.8',
            ],
            'weights' => [
                'gross_weight_kg' => '3500',
            ],
            'habitation_basics' => [
                'has_bathroom' => '1',
                'has_kitchen'  => true,
            ],
            'beds' => [
                ['type' => 'central'],
                ['type' => 'rebatível na cabine'],
                ['type' => ''],
            ],
            'autonomy_km' => '500',
        ]);

        $this->assertSame(6.5, $normalized['dimensions']['length_m']);
        $this->assertSame(2.2, $normalized['dimensions']['width_m']);
        $this->assertSame(2.8, $normalized['dimensions']['height_m']);

        $this->assertSame(3500, $normalized['weights']['gross_weight_kg']);

        $this->assertTrue($normalized['habitation_basics']['has_bathroom']);
        $this->assertTrue($normalized['habitation_basics']['has_kitchen']);

        $this->assertSame([
            ['type' => 'central'],
            ['type' => 'rebatível na cabine'],
        ], $normalized['beds']);

        $this->assertSame(500, $normalized['autonomy_km']);
        $this->assertArrayNotHasKey('autonomy', $normalized);
    }

    public function test_it_drops_empty_section_keys(): void
    {
        $normalized = $this->normalizeVehicleAttributes([
            'dimensions' => [
                'length_m' => '6.0',
                'width_m'  => '',
            ],
        ]);

        $this->assertSame(6.0, $normalized['dimensions']['length_m']);
        $this->assertArrayNotHasKey('width_m', $normalized['dimensions']);
    }

    public function test_it_preserves_root_string_keys(): void
    {
        $normalized = $this->normalizeVehicleAttributes([
            'chassis_type' => 'integral',
        ]);

        $this->assertSame('integral', $normalized['chassis_type']);
    }

    private function normalizeVehicleAttributes(array $attributes): array
    {
        $service = (new ReflectionClass(CarService::class))->newInstanceWithoutConstructor();
        $method = (new ReflectionClass(CarService::class))->getMethod('extractVehicleAttributes');
        $method->setAccessible(true);

        return $method->invoke($service, [
            'vehicle_attributes' => $attributes,
        ]);
    }
}
