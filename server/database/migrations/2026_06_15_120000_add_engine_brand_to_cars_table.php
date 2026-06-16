<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M2.2 — chassis_brand em cars
 *
 * Marca do chassis (Fiat / Renault / Ford / …) — distinta da marca COMERCIAL
 * (Challenger / McLouis / …). Aplica-se a autocaravanas e caravanas
 * (motorhome/caravan); carros ficam com NULL (sem coerção).
 *
 * String nullable + Rule::in com whitelist no Form Request (constante
 * extensível). Valor sai cru ao /specs interno; API pública NÃO tocada
 * nesta sub-fase (sec 2.5/16 — breaking change coordenado fica para tarefa
 * futura quando os sites externos quiserem expor).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cars', function (Blueprint $table) {
            $table->string('chassis_brand')->nullable()->after('public_version_name');
        });
    }

    public function down(): void
    {
        Schema::table('cars', function (Blueprint $table) {
            $table->dropColumn('chassis_brand');
        });
    }
};
