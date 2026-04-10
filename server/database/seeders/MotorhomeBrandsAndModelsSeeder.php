<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class MotorhomeBrandsAndModelsSeeder extends Seeder
{
    public function run(): void
    {
        $json = file_get_contents(database_path('seeders/data/motorhome_brands_models.json'));
        $data = json_decode($json, true);

        $now = Carbon::now();

        foreach ($data as $entry) {
            $vehicleType = $entry['vehicle_type'];

            // inserir brand respeitando slug + vehicle_type
            DB::table('car_brands')->updateOrInsert(
                [
                    'slug' => $entry['brand']['slug'],
                    'vehicle_type' => $vehicleType,
                ],
                [
                    'name' => $entry['brand']['name'],
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );

            // buscar brand correto por slug + vehicle_type
            $brand = DB::table('car_brands')
                ->where('slug', $entry['brand']['slug'])
                ->where('vehicle_type', $vehicleType)
                ->first();

            if (!$brand) {
                continue;
            }

            foreach ($entry['models_recommended'] ?? [] as $model) {
                DB::table('car_models')->updateOrInsert(
                    [
                        'car_brand_id' => $brand->id,
                        'name' => $model,
                        'vehicle_type' => $vehicleType,
                    ],
                    [
                        'type' => 'recommended',
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );
            }

            foreach ($entry['models_other'] ?? [] as $model) {
                DB::table('car_models')->updateOrInsert(
                    [
                        'car_brand_id' => $brand->id,
                        'name' => $model,
                        'vehicle_type' => $vehicleType,
                    ],
                    [
                        'type' => 'other',
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );
            }
        }
    }
}
