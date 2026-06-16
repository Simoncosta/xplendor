<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M2.2 — engine_brand em cars
 *
 * Marca do MOTOR (Fiat / Renault / Ford / Mercedes / Citroën / …) — distinta
 * da marca COMERCIAL da autocaravana (Challenger / McLouis / …). A Matilde
 * confirmou: "a autocaravana é Challenger, o motor por baixo é Fiat".
 *
 * Aplica-se a autocaravanas e caravanas (motorhome/caravan); carros ficam
 * com NULL (sem coerção).
 *
 * NÃO confundir com `vehicle_attributes.chassis_structure.chassis_type` —
 * que é o TIPO de chassis (standard / alko / other), atributo de habitação
 * no JSON. `engine_brand` é coluna de `cars`, propriedade base do veículo.
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
            $table->string('engine_brand')->nullable()->after('public_version_name');
        });
    }

    public function down(): void
    {
        Schema::table('cars', function (Blueprint $table) {
            $table->dropColumn('engine_brand');
        });
    }
};
