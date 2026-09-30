<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — FASE 0 do motor de preço de autocaravanas.
 *
 * Colunas novas (todas nullable — nem todo o anúncio declara tudo):
 *   - beds          nº de dormidas ("Lugares dormida 4", "4 Dormidas", …)
 *   - layout        tipologia canónica (perfiladas/integral/capucine/furgao/
 *                   caravana) — por-anúncio (body_type estruturado do detalhe
 *                   Standvirtual, subcategoria CustoJusto, ou texto)
 *   - displacement  cilindrada em cm³ (parameter engine_capacity da listagem
 *                   Standvirtual, ou parsing do texto)
 *   - length        comprimento em metros (parsing da descrição)
 *
 * Migração ADITIVA: o motor de matching/cálculo atual não é tocado; estes
 * campos ficam disponíveis para a Fase 1 (similaridade ponderada).
 * beds e layout levam índice — são os candidatos a filtro na Fase 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('car_market_snapshots', function (Blueprint $table) {
            $table->unsignedTinyInteger('beds')->nullable()->index()->after('doors');
            $table->string('layout', 30)->nullable()->index()->after('beds');
            $table->unsignedSmallInteger('displacement')->nullable()->after('layout');
            $table->decimal('length', 4, 2)->nullable()->after('displacement');
        });
    }

    public function down(): void
    {
        Schema::table('car_market_snapshots', function (Blueprint $table) {
            $table->dropIndex(['beds']);
            $table->dropIndex(['layout']);
            $table->dropColumn(['beds', 'layout', 'displacement', 'length']);
        });
    }
};
