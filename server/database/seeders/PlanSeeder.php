<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Plan::insert([
            [
                'name' => 'Xplendor Drive',
                'price' => 99.00,
                'car_limit' => 50,
            ],
            [
                'name' => 'Xplendor Performance',
                'price' => 199.00,
                'car_limit' => 200,
            ],
        ]);
    }
}
