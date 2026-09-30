<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — FASE 1 (motor de similaridade de autocaravanas): campos do
 * aggregate para o novo contrato de confiança/banda.
 *
 *   - p25_price / p75_price  banda central do mercado (quantis R-7).
 *                            SÓ preenchidos com n>=4 — com n<4 ficam NULL
 *                            (min/max têm colunas próprias; gravar min/max
 *                            aqui envenenaria a semântica dos quantis).
 *   - outliers_removed       nº de preços removidos (guarda grosseira + IQR)
 *   - method                 identificador do motor que produziu o aggregate
 *                            ('motorhome_similarity_v1'; NULL = cascata
 *                            clássica de carros). A UI usa-o para o selo
 *                            "comparação por tipologia+ano, não por modelo".
 *
 * Aditivo: o caminho dos carros não escreve nestes campos (method NULL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('car_market_aggregates', function (Blueprint $table) {
            $table->decimal('p25_price', 12, 2)->nullable()->after('median_price');
            $table->decimal('p75_price', 12, 2)->nullable()->after('p25_price');
            $table->unsignedSmallInteger('outliers_removed')->default(0)->after('comparables_count');
            $table->string('method', 40)->nullable()->after('vehicle_type');
        });
    }

    public function down(): void
    {
        Schema::table('car_market_aggregates', function (Blueprint $table) {
            $table->dropColumn(['p25_price', 'p75_price', 'outliers_removed', 'method']);
        });
    }
};
