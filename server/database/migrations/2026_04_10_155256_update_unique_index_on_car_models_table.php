<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('car_models', function (Blueprint $table) {
            // criar índice simples para suportar a FK antes de remover o unique antigo
            $table->index('car_brand_id', 'car_models_car_brand_id_index_temp');
        });

        Schema::table('car_models', function (Blueprint $table) {
            $table->dropUnique('car_models_car_brand_id_name_unique');
            $table->unique(
                ['car_brand_id', 'name', 'vehicle_type'],
                'car_models_brand_name_vehicle_type_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('car_models', function (Blueprint $table) {
            $table->dropUnique('car_models_brand_name_vehicle_type_unique');
            $table->unique(
                ['car_brand_id', 'name'],
                'car_models_car_brand_id_name_unique'
            );
        });

        Schema::table('car_models', function (Blueprint $table) {
            $table->dropIndex('car_models_car_brand_id_index_temp');
        });
    }
};
