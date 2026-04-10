<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cars', function (Blueprint $table) {
            $table->foreignId('car_category_id')
                ->nullable()
                ->after('vehicle_type')
                ->constrained('car_categories')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cars', function (Blueprint $table) {
            $table->dropConstrainedForeignId('car_category_id');
        });
    }
};
