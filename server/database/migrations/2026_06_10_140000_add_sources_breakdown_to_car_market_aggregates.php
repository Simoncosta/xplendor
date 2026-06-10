<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MS2.e — sources_breakdown em car_market_aggregates
 *
 * JSON nullable com contagens pós-dedup por fonte que alimentou o pool da
 * mediana. Ex.: {"standvirtual": 8, "custojusto": 4} → "Análise baseada em
 * 12 anúncios · 2 fontes" no UI (MS2.f).
 *
 * Calculado DEPOIS do dedup em leitura (acréscimo 2 da MS2.e): anúncios
 * cross-postados contam na fonte vencedora (standvirtual pela ordem
 * determinística). O breakdown reflecte o pool real que alimentou a mediana,
 * não o que foi ingerido — coerente com o contador "N anúncios" do UI.
 *
 * Nullable porque snapshots históricos não têm breakdown e podem ser
 * exibidos sem o chip extra (fallback gracioso no frontend).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('car_market_aggregates', function (Blueprint $table) {
            $table->json('sources_breakdown')->nullable()->after('search_url');
        });
    }

    public function down(): void
    {
        Schema::table('car_market_aggregates', function (Blueprint $table) {
            $table->dropColumn('sources_breakdown');
        });
    }
};
