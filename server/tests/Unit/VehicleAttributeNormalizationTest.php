<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\VehicleAttribute;
use Tests\TestCase;

class VehicleAttributeNormalizationTest extends TestCase
{
    // ------------------------------------------------------------------ //
    //  empty / null inputs
    // ------------------------------------------------------------------ //

    public function test_null_returns_empty_shape(): void
    {
        $result = VehicleAttribute::normalizeShape(null);

        $this->assertArrayHasKey('dimensions', $result);
        $this->assertArrayHasKey('weights', $result);
        $this->assertArrayHasKey('habitation_basics', $result);
        $this->assertArrayHasKey('beds', $result);
        $this->assertEmpty($result['dimensions']);
        $this->assertEmpty($result['beds']);
    }

    public function test_empty_array_returns_empty_shape(): void
    {
        $result = VehicleAttribute::normalizeShape([]);

        $this->assertEquals(VehicleAttribute::emptyShape(), $result);
    }

    // ------------------------------------------------------------------ //
    //  old flat format → new nested format
    // ------------------------------------------------------------------ //

    public function test_converts_dimensions_from_cm_to_metres(): void
    {
        $result = VehicleAttribute::normalizeShape([
            'length' => 600,
            'width'  => 240,
            'height' => 250,
        ]);

        $this->assertSame(6.0,  $result['dimensions']['length_m']);
        $this->assertSame(2.4,  $result['dimensions']['width_m']);
        $this->assertSame(2.5,  $result['dimensions']['height_m']);
    }

    public function test_migrates_gross_weight_to_weights_section(): void
    {
        $result = VehicleAttribute::normalizeShape(['gross_weight' => 3300]);

        $this->assertSame(3300, $result['weights']['gross_weight_kg']);
        $this->assertArrayNotHasKey('gross_weight', $result);
    }

    public function test_migrates_has_bathroom_and_has_kitchen_to_habitation_basics(): void
    {
        $result = VehicleAttribute::normalizeShape([
            'has_bathroom' => true,
            'has_kitchen'  => true,
        ]);

        $this->assertTrue($result['habitation_basics']['has_bathroom']);
        $this->assertTrue($result['habitation_basics']['has_kitchen']);
        $this->assertArrayNotHasKey('has_bathroom', $result);
        $this->assertArrayNotHasKey('has_kitchen', $result);
    }

    public function test_renames_autonomy_to_autonomy_km_at_root(): void
    {
        $result = VehicleAttribute::normalizeShape(['autonomy' => 800]);

        $this->assertSame(800, $result['autonomy_km']);
        $this->assertArrayNotHasKey('autonomy', $result);
    }

    public function test_beds_stays_at_root_unchanged(): void
    {
        $beds = [['type' => 'central'], ['type' => 'cama de garagem']];

        $result = VehicleAttribute::normalizeShape(['beds' => $beds]);

        $this->assertSame($beds, $result['beds']);
    }

    // ------------------------------------------------------------------ //
    //  new nested format → returned as-is
    // ------------------------------------------------------------------ //

    public function test_new_format_is_returned_as_is(): void
    {
        $input = ['dimensions' => ['length_m' => 6.0, 'width_m' => 2.4]];

        $result = VehicleAttribute::normalizeShape($input);

        $this->assertSame($input, $result);
    }

    // ------------------------------------------------------------------ //
    //  real data cases from DB
    // ------------------------------------------------------------------ //

    public function test_real_motorhome_car_id_55_converts_correctly(): void
    {
        // Exact attributes stored in DB for car_id 55
        $raw = [
            'beds'         => [['type' => 'cama de garagem']],
            'gross_weight' => 3300,
            'has_bathroom' => true,
            'has_kitchen'  => true,
            'height'       => 250,
            'length'       => 600,
            'width'        => 240,
        ];

        $result = VehicleAttribute::normalizeShape($raw);

        $this->assertSame(6.0, $result['dimensions']['length_m']);
        $this->assertSame(2.4, $result['dimensions']['width_m']);
        $this->assertSame(2.5, $result['dimensions']['height_m']);
        $this->assertSame(3300, $result['weights']['gross_weight_kg']);
        $this->assertTrue($result['habitation_basics']['has_bathroom']);
        $this->assertTrue($result['habitation_basics']['has_kitchen']);
        $this->assertSame([['type' => 'cama de garagem']], $result['beds']);
    }

    // ------------------------------------------------------------------ //
    //  emptyShape includes kitchen and bathroom sub-objects
    // ------------------------------------------------------------------ //

    public function test_empty_shape_includes_kitchen_and_bathroom_in_habitation_basics(): void
    {
        $shape = VehicleAttribute::emptyShape();

        $this->assertArrayHasKey('habitation_basics', $shape);
        $this->assertArrayHasKey('kitchen',  $shape['habitation_basics']);
        $this->assertArrayHasKey('bathroom', $shape['habitation_basics']);
        $this->assertIsArray($shape['habitation_basics']['kitchen']);
        $this->assertIsArray($shape['habitation_basics']['bathroom']);
    }

    public function test_new_format_with_kitchen_details_survives_normalisation(): void
    {
        $input = [
            'habitation_basics' => [
                'has_kitchen' => true,
                'kitchen' => [
                    'has_stove'    => true,
                    'has_fridge'   => true,
                    'fridge_type'  => 'trivalent',
                    'fridge_litres' => 142,
                ],
            ],
        ];

        $result = VehicleAttribute::normalizeShape($input);

        // new format → returned as-is
        $this->assertSame($input, $result);
        $this->assertTrue($result['habitation_basics']['has_kitchen']);
        $this->assertTrue($result['habitation_basics']['kitchen']['has_stove']);
        $this->assertSame('trivalent', $result['habitation_basics']['kitchen']['fridge_type']);
        $this->assertSame(142, $result['habitation_basics']['kitchen']['fridge_litres']);
    }

    public function test_new_format_with_bathroom_details_survives_normalisation(): void
    {
        $input = [
            'habitation_basics' => [
                'has_bathroom' => true,
                'bathroom' => [
                    'has_toilet'          => true,
                    'has_shower'          => true,
                    'shower_type'         => 'separate',
                    'clean_water_litres'  => 100,
                    'waste_water_litres'  => 90,
                ],
            ],
        ];

        $result = VehicleAttribute::normalizeShape($input);

        $this->assertSame($input, $result);
        $this->assertTrue($result['habitation_basics']['has_bathroom']);
        $this->assertSame('separate', $result['habitation_basics']['bathroom']['shower_type']);
        $this->assertSame(100, $result['habitation_basics']['bathroom']['clean_water_litres']);
    }

    public function test_bug_car_id_57_negative_length_normalises_without_throwing(): void
    {
        // car_id 57 has length: -0.1745 (data entry bug — normaliser must not crash)
        $raw = [
            'length'       => -0.1745,
            'width'        => 235,
            'has_bathroom' => true,
            'has_kitchen'  => true,
        ];

        $result = VehicleAttribute::normalizeShape($raw);

        // -0.1745 cm ÷ 100 = -0.001745 m → rounds to 0.0 at 2dp
        $this->assertIsFloat($result['dimensions']['length_m']);
        $this->assertSame(0.0, $result['dimensions']['length_m']);

        // width converts correctly
        $this->assertSame(2.35, $result['dimensions']['width_m']);

        // habitation migrated
        $this->assertTrue($result['habitation_basics']['has_bathroom']);
    }
}
