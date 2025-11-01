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
                'name' => 'Start',
                'price' => 16.99,
                'car_limit' => 1,
            ],
            [
                'name' => 'Standard',
                'price' => 19.99,
                'car_limit' => 5,
            ],
            [
                'name' => 'Advanced',
                'price' => 29.99,
                'car_limit' => 10,
            ],
            [
                'name' => 'Expert',
                'price' => 38.99,
                'car_limit' => 30,
            ]
        ]);
    }
}
