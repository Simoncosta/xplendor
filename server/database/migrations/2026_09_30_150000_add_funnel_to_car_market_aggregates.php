<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — FASE 1 (motor de autocaravanas): funil de elegibilidade.
 *
 * Com status=none, o motor guarda QUANTOS anúncios se perderam em cada
 * filtro ("vimos X desta tipologia; Y na janela de ano; Z recentes; W com
 * preço plausível") para a UI explicar o vazio em vez de silêncio.
 * Coluna própria porque `sources_breakdown` tem shape {fonte: n} consumido
 * pelo frontend (MS2.f) — misturar lá o funil partia esse contrato.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('car_market_aggregates', function (Blueprint $table) {
            $table->json('funnel')->nullable()->after('sources_breakdown');
        });
    }

    public function down(): void
    {
        Schema::table('car_market_aggregates', function (Blueprint $table) {
            $table->dropColumn('funnel');
        });
    }
};
