<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CarCategoriesSeeder extends Seeder
{
    public function run(): void
    {
        $json = file_get_contents(database_path('seeders/data/motorhome_categories.json'));
        $data = json_decode($json, true);

        $now = Carbon::now();

        foreach ($data as $group) {
            $vehicleType = $group['vehicle_type'];

            foreach ($group['categories'] as $category) {
                DB::table('car_categories')->updateOrInsert(
                    [
                        'slug' => $category['slug']
                    ],
                    [
                        'name' => $category['name'],
                        'vehicle_type' => $vehicleType,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            }
        }
    }
}
