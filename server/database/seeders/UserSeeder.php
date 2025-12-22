<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::insert([
            [
                'name' => 'Admin Xplendor',
                'email' => 'admin@xplendor.pt',
                'password' => bcrypt('admin'),
                'role' => 'root',
                'company_id' => 1
            ],
            // [
            //     'name' => 'User Xplendor',
            //     'email' => 'user@xplendor.eu',
            //     'password' => bcrypt('user'),
            //     'role' => 'user',
            // ],
            // [
            //     'name' => 'Manager Xplendor',
            //     'email' => 'manager@xplendor.eu',
            //     'password' => bcrypt('manager'),
            //     'role' => 'manager',
            // ],
            // [
            //     'name' => 'Seller Xplendor',
            //     'email' => 'seller@xplendor.eu',
            //     'password' => bcrypt('seller'),
            //     'role' => 'seller',
            // ]
        ]);
    }
}
