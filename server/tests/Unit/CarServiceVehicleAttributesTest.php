<?php

namespace Tests\Unit;

use App\Services\CarService;
use ReflectionClass;
use Tests\TestCase;

class CarServiceVehicleAttributesTest extends TestCase
{
    public function test_it_normalizes_motorhome_vehicle_attributes_json(): void
    {
        $normalized = $this->normalizeVehicleAttributes([
            'length' => '6.5',
            'width' => '2.2',
            'height' => '2.8',
            'gross_weight' => '3500',
            'beds' => [
                ['type' => 'central'],
                ['type' => 'rebatível na cabine'],
                ['type' => ''],
            ],
            'has_bathroom' => '1',
            'has_kitchen' => true,
            'autonomy_km' => '500',
        ]);

        $this->assertSame(6.5, $normalized['length']);
        $this->assertSame(3500, $normalized['gross_weight']);
        $this->assertSame([
            ['type' => 'central'],
            ['type' => 'rebatível na cabine'],
        ], $normalized['beds']);
        $this->assertTrue($normalized['has_bathroom']);
        $this->assertTrue($normalized['has_kitchen']);
        $this->assertSame(500, $normalized['autonomy']);
        $this->assertArrayNotHasKey('autonomy_km', $normalized);
    }

    private function normalizeVehicleAttributes(array $attributes): array
    {
        $service = (new ReflectionClass(CarService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionClass(CarService::class)->getMethod('extractVehicleAttributes');
        $method->setAccessible(true);

        return $method->invoke($service, [
            'vehicle_attributes' => $attributes,
        ]);
    }
}
