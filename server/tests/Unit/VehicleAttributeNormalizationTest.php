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

    public function test_beds_old_strings_are_migrated_to_slugs(): void
    {
        $result = VehicleAttribute::normalizeShape([
            'beds' => [['type' => 'central'], ['type' => 'cama de garagem']],
        ]);

        $this->assertSame('cama_central', $result['beds'][0]['type']);
        $this->assertSame('cama_garagem', $result['beds'][1]['type']);
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
        $this->assertSame([['type' => 'cama_garagem']], $result['beds']);
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

    // ------------------------------------------------------------------ //
    //  B2 — 5 new sections
    // ------------------------------------------------------------------ //

    public function test_empty_shape_includes_all_b2_sections(): void
    {
        $shape = VehicleAttribute::emptyShape();

        foreach (['energy_climate', 'exterior', 'security', 'chassis_structure', 'interior_furniture'] as $section) {
            $this->assertArrayHasKey($section, $shape, "emptyShape missing section: {$section}");
            $this->assertIsArray($shape[$section]);
        }
    }

    public function test_b2_section_key_alone_triggers_as_is_return(): void
    {
        // Payload that has NO B1 keys — only a B2 key.
        // normalizeShape must not fall through to migrateFromOldShape.
        $input = [
            'energy_climate' => [
                'has_solar_panel'   => true,
                'solar_panel_watts' => 150,
            ],
        ];

        $result = VehicleAttribute::normalizeShape($input);

        $this->assertSame($input, $result);
        $this->assertTrue($result['energy_climate']['has_solar_panel']);
    }

    public function test_normalizeShape_passes_has_generator_through(): void
    {
        // has_generator: campo novo (boolean) — pass-through directo.
        // Registos antigos sem o campo NÃO falham: a chave simplesmente
        // não existe e o frontend trata undefined como false.
        $withGenerator = VehicleAttribute::normalizeShape([
            'energy_climate' => ['has_generator' => true],
        ]);
        $this->assertTrue($withGenerator['energy_climate']['has_generator']);

        $withoutGenerator = VehicleAttribute::normalizeShape([
            'energy_climate' => ['has_solar_panel' => true],
        ]);
        $this->assertArrayNotHasKey('has_generator', $withoutGenerator['energy_climate']);
    }

    public function test_old_flat_record_does_not_gain_spurious_b2_sections(): void
    {
        // Typical old DB record — none of the B2 section keys should appear in output.
        $raw = [
            'length'       => 600,
            'gross_weight' => 3300,
            'has_bathroom' => true,
            'has_kitchen'  => true,
        ];

        $result = VehicleAttribute::normalizeShape($raw);

        foreach (['energy_climate', 'exterior', 'security', 'chassis_structure', 'interior_furniture'] as $section) {
            $this->assertArrayNotHasKey($section, $result, "migrateFromOldShape should not add: {$section}");
        }
    }

    public function test_full_b2_payload_survives_normalisation(): void
    {
        $input = [
            'dimensions'        => ['length_m' => 7.0],
            'energy_climate'    => [
                'has_solar_panel'          => true,
                'solar_panel_watts'        => 200,
                'water_heater_source'      => 'gas',
                'water_heater_brand'       => 'Truma',
                'has_external_power_socket' => false,
                'battery_count'            => 2,
            ],
            'exterior'          => [
                'has_awning'   => true,
                'awning_brand' => 'Thule',
                'has_bike_rack' => false,
            ],
            'security'          => [
                'has_alarm'          => true,
                'has_entry_door_lock' => true,
                'other_locks_notes'  => 'Fechadura adicional na bagageira',
            ],
            'chassis_structure' => [
                'chassis_type'            => 'alko',
                'has_turbovent_skylight'  => true,
                'has_cabin_blackouts'     => true,
                'cabin_blackout_type'     => 'magnético',
            ],
            'interior_furniture' => [
                'has_foldable_table'     => true,
                'upholstery_state'       => 'good',
                'has_water_infiltrations' => false,
            ],
        ];

        $result = VehicleAttribute::normalizeShape($input);

        $this->assertSame($input, $result);
        $this->assertSame('gas',   $result['energy_climate']['water_heater_source']);
        $this->assertSame(200,     $result['energy_climate']['solar_panel_watts']);
        $this->assertSame('Thule', $result['exterior']['awning_brand']);
        $this->assertSame('alko',  $result['chassis_structure']['chassis_type']);
        $this->assertSame('good',  $result['interior_furniture']['upholstery_state']);
    }

    // ------------------------------------------------------------------ //
    //  C — normalizeBedTypes
    // ------------------------------------------------------------------ //

    public function test_normalize_bed_types_empty_array_returns_empty(): void
    {
        $result = VehicleAttribute::normalizeShape(['beds' => []]);

        $this->assertSame([], $result['beds']);
    }

    public function test_normalize_bed_types_central_maps_to_cama_central(): void
    {
        $result = VehicleAttribute::normalizeShape(['beds' => [['type' => 'central']]]);

        $this->assertSame('cama_central', $result['beds'][0]['type']);
    }

    public function test_normalize_bed_types_cama_de_garagem_maps_to_cama_garagem(): void
    {
        $result = VehicleAttribute::normalizeShape([
            'beds' => [['type' => 'cama de garagem']],
        ]);

        $this->assertSame('cama_garagem', $result['beds'][0]['type']);
    }

    public function test_normalize_bed_types_valid_slug_passes_through_unchanged(): void
    {
        $result = VehicleAttribute::normalizeShape([
            'dimensions' => ['length_m' => 6.0],
            'beds'       => [['type' => 'cama_basculante']],
        ]);

        $this->assertSame('cama_basculante', $result['beds'][0]['type']);
    }

    public function test_normalize_bed_types_unknown_string_falls_back_to_outra(): void
    {
        $result = VehicleAttribute::normalizeShape(['beds' => [['type' => 'dupla']]]);

        $this->assertSame('outra', $result['beds'][0]['type']);
    }
}
