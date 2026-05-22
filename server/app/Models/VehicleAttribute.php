<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VehicleAttribute extends Model
{
    protected $table = 'vehicle_attributes';

    protected $fillable = [
        'car_id',
        'attributes',
    ];

    protected $casts = [
        'attributes' => 'array',
    ];

    /**
     * Canonical empty shape for the B1 attributes structure.
     */
    public static function emptyShape(): array
    {
        return [
            'dimensions'         => [],
            'weights'            => [],
            'habitation_basics'  => [
                'kitchen'  => [],
                'bathroom' => [],
            ],
            'beds'               => [],
            'energy_climate'     => [],
            'exterior'           => [],
            'security'           => [],
            'chassis_structure'  => [],
            'interior_furniture' => [],
        ];
    }

    /**
     * Normalise raw attributes to the B1 nested shape.
     *
     * Accepts null/empty, old flat format, or new nested format.
     * Single source of truth: Car model accessor, CarDescriptionService,
     * and MigrateVehicleAttributesShapeCommand all go through here.
     */
    public static function normalizeShape(?array $raw): array
    {
        if (empty($raw)) {
            return self::emptyShape();
        }

        // Already in new format — at least one known section key present.
        // Still normalise beds: early B1 records preserved the old string values.
        if (array_intersect_key($raw, array_flip([
            'dimensions', 'weights', 'habitation_basics',
            'energy_climate', 'exterior', 'security', 'chassis_structure', 'interior_furniture',
        ]))) {
            if (!empty($raw['beds'])) {
                $raw['beds'] = self::normalizeBedTypes($raw['beds']);
            }
            return $raw;
        }

        return self::migrateFromOldShape($raw);
    }

    /**
     * Convert old flat attributes (length/width/height in cm, gross_weight, etc.)
     * to the B1 nested structure.
     *
     * Units: length/width/height cm → m (÷100, 2dp).
     * Negative values are preserved — Form Request validation, not this method, rejects them.
     */
    protected static function migrateFromOldShape(array $old): array
    {
        $result = [
            'dimensions'        => [],
            'weights'           => [],
            'habitation_basics' => [],
        ];

        // dimensions: cm → m
        if (isset($old['length']) && is_numeric($old['length'])) {
            $result['dimensions']['length_m'] = round((float) $old['length'] / 100, 2);
        }
        if (isset($old['width']) && is_numeric($old['width'])) {
            $result['dimensions']['width_m'] = round((float) $old['width'] / 100, 2);
        }
        if (isset($old['height']) && is_numeric($old['height'])) {
            $result['dimensions']['height_m'] = round((float) $old['height'] / 100, 2);
        }

        // weights
        if (isset($old['gross_weight']) && is_numeric($old['gross_weight'])) {
            $result['weights']['gross_weight_kg'] = (int) $old['gross_weight'];
        }

        // habitation_basics
        if (isset($old['has_bathroom'])) {
            $result['habitation_basics']['has_bathroom'] = (bool) $old['has_bathroom'];
        }
        if (isset($old['has_kitchen'])) {
            $result['habitation_basics']['has_kitchen'] = (bool) $old['has_kitchen'];
        }

        // autonomy rename stays at root
        if (isset($old['autonomy']) && is_numeric($old['autonomy'])) {
            $result['autonomy_km'] = (int) $old['autonomy'];
        }

        // beds stays at root — normalise type slugs
        if (isset($old['beds']) && is_array($old['beds'])) {
            $result['beds'] = self::normalizeBedTypes($old['beds']);
        }

        return $result;
    }

    /**
     * Migrate legacy bed type strings to canonical slugs.
     * Idempotent: valid slugs pass through unchanged.
     */
    private static function normalizeBedTypes(array $beds): array
    {
        $map = [
            'central'              => 'cama_central',
            'rebatível na cabine'  => 'cama_rebativel_cabine',
            'beliche'              => 'beliche',
            'transversal'          => 'cama_transversal',
            'cama de garagem'      => 'cama_garagem',
            'outra'                => 'outra',
        ];

        $validSlugs = [
            'camas_gemeas', 'cama_central', 'cama_francesa', 'cama_basculante',
            'cama_capucino', 'cama_garagem', 'beliche', 'cama_transversal',
            'cama_elevatoria_eletrica', 'cama_suspensa', 'cama_convertivel',
            'outra', 'cama_rebativel_cabine',
        ];

        return array_map(static function (array $bed) use ($map, $validSlugs): array {
            $type = $bed['type'] ?? '';

            if (in_array($type, $validSlugs, true)) {
                return $bed;
            }

            $bed['type'] = $map[$type] ?? 'outra';
            return $bed;
        }, $beds);
    }
}
