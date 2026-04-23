<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('car_brands', function (Blueprint $table) {
            $table->enum('vehicle_type', ['car', 'motorcycle', 'motorhome'])
                ->default('car')
                ->after('slug');

            $table->index('vehicle_type');
        });
    }

    public function down(): void
    {
        Schema::table('car_brands', function (Blueprint $table) {
            $table->dropIndex(['vehicle_type']);
            $table->dropColumn('vehicle_type');
        });
    }
};
