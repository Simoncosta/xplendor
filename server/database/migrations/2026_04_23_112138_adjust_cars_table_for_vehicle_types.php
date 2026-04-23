<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cars', function (Blueprint $table) {

            // 1. vehicle_type (se não existir ou estiver errado)

            if (!Schema::hasColumn('cars', 'vehicle_type')) {
                $table->enum('vehicle_type', ['car', 'motorcycle', 'motorhome', 'caravan'])
                    ->default('car')
                    ->after('id');
            } else {
                $table->enum('vehicle_type', ['car', 'motorcycle', 'motorhome', 'caravan'])
                    ->default('car')
                    ->change();
            }

            // 2. Tornar campos de motor opcionais
            $table->string('fuel_type')->nullable()->change();
            $table->unsignedSmallInteger('power_hp')->nullable()->change();
            $table->unsignedSmallInteger('engine_capacity_cc')->nullable()->change();
            $table->string('transmission')->nullable()->change();
        });
    }

    public function down(): void

    {

        Schema::table('cars', function (Blueprint $table) {
            // rollback básico (não crítico)
            $table->string('fuel_type')->nullable(false)->change();
            $table->unsignedSmallInteger('power_hp')->nullable(false)->change();
            $table->unsignedSmallInteger('engine_capacity_cc')->nullable(false)->change();
            $table->string('transmission')->nullable(false)->change();
        });
    }
};
