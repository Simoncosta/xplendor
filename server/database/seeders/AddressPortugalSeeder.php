<?php

namespace Database\Seeders;

use App\Models\District;
use App\Models\Municipality;
use App\Models\Parish;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Maatwebsite\Excel\Facades\Excel;

class AddressPortugalSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        ini_set('memory_limit', '512M');

        $path = storage_path('app/private/freguesias-de-Portugal.xlsx');

        // Lê tudo para uma Collection
        $sheets = Excel::toCollection(null, $path);

        // pega a primeira sheet
        $rows = $sheets[0];

        collect($rows)->each(function ($row) {
            if (empty($row[1]) || $row[0] === "Distrito (Açores e Madeira, por ilhas)") return;

            $district = District::whereName($row[0])->first();
            if (empty($district)) {
                $district = District::create([
                    'name' => $row[0],
                ]);
            }

            $municipalities = Municipality::whereName($row[1])->first();
            if (empty($municipalities)) {
                $municipalities = Municipality::create([
                    'name' => $row[1],
                    'website' => $row[4],
                    'district_id' => $district->id,
                ]);
            }

            $parish = Parish::whereName($row[2])->first();
            if (empty($parish)) {
                $parish = Parish::create([
                    'name' => $row[2],
                    'website' => preg_replace('/(\.pt).*/i', '$1', $row[7]),
                    'facebook' => $row[9] === "_" ? null : $row[9],
                    'municipality_id' => $municipalities->id,
                ]);
            }
        });
    }
}
