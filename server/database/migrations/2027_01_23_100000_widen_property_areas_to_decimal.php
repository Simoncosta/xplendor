<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Imóveis (e frações dos empreendimentos, que são imóveis): a área útil e a área bruta com
 * decimais (92,10 m²). Exceção autorizada à regra das migrações só aditivas: só alarga o tipo
 * (de inteiro até 65 535 para decimal(8,2)), sem perda de dados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->decimal('area_m2', 8, 2)->nullable()->change();
            $table->decimal('gross_area_m2', 8, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Volta ao inteiro (arredonda as casas decimais).
        Schema::table('properties', function (Blueprint $table) {
            $table->unsignedSmallInteger('area_m2')->nullable()->change();
            $table->unsignedSmallInteger('gross_area_m2')->nullable()->change();
        });
    }
};
