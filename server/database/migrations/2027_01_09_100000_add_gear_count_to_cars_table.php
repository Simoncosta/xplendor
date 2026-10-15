<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ficha das autocaravanas (B): número de velocidades da caixa (opcional). Só aditiva: os
 * carros não o usam e ficam iguais.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cars', function (Blueprint $table) {
            $table->unsignedTinyInteger('gear_count')->nullable()->after('transmission');
        });
    }

    public function down(): void
    {
        Schema::table('cars', function (Blueprint $table) {
            $table->dropColumn('gear_count');
        });
    }
};
