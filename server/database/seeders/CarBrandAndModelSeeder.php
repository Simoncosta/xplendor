<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

class CarBrandAndModelSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $json = file_get_contents(database_path('seeders/data/marcas_modelos.json'));
        $data = json_decode($json, true);
        $now = Carbon::now();

        foreach ($data as $entry) {
            $brandId = DB::table('car_brands')->insertGetId([
                'name' => $entry['marca'],
                'slug' => $entry['slug'],
                // 'logo' => '/logos/' . $entry['slug'] . '.png',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $modelosRecomendados = $entry['modelos_recomendados'] ?? [];
            $modelosOutros = $entry['modelos_outros'] ?? [];

            $models = [];

            foreach ($modelosRecomendados as $model) {
                $models[] = [
                    'car_brand_id' => $brandId,
                    'name' => $model,
                    'type' => 'recommended',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach ($modelosOutros as $model) {
                $models[] = [
                    'car_brand_id' => $brandId,
                    'name' => $model,
                    'type' => 'other',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('car_models')->insert($models);
        }
    }
}
