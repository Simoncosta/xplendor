<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('car_brands', function (Blueprint $table) {
            // criar index simples para suportar FK (seguro)
            $table->index('slug', 'car_brands_slug_index_temp');
        });

        Schema::table('car_brands', function (Blueprint $table) {
            // remover unique antigo
            $table->dropUnique('car_brands_slug_unique');

            // novo unique composto
            $table->unique(
                ['slug', 'vehicle_type'],
                'car_brands_slug_vehicle_type_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('car_brands', function (Blueprint $table) {
            $table->dropUnique('car_brands_slug_vehicle_type_unique');

            $table->unique('slug', 'car_brands_slug_unique');
        });

        Schema::table('car_brands', function (Blueprint $table) {
            $table->dropIndex('car_brands_slug_index_temp');
        });
    }
};
