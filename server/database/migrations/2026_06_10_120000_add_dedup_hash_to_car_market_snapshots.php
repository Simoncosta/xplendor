<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MS2.a — dedup_hash em car_market_snapshots
 *
 * Preparação para a deduplicação cross-fonte (MS2.d): o mesmo anúncio
 * publicado em Standvirtual + CustoJusto (cross-posting é comum entre
 * profissionais) não pode contar duas vezes na mediana do aggregate.
 *
 * Hash calculado na ingestão como sha1(normalized_title | year | price_bucket_100).
 * Indexed para futuros lookups (avaliar se composite com `car_id` quando o
 * âmbito do dedup mudar). Nullable para tolerar snapshots antigos sem hash.
 *
 * Decisão MS2.a: NÃO adicionar `source_listing_id` — a auditoria de 2026-06-10
 * confirmou que `external_id` já é o ID do anúncio na fonte (Standvirtual:
 * 8097558322 vindo de `ad.id`; CustoJusto vai usar `listID` directo). O unique
 * composite ['source', 'external_id'] já garante separação cross-fonte.
 *
 * Decisão MS2.a: NÃO mexer em `source` — já existe NOT NULL e o scraper actual
 * já o envia obrigatoriamente; o aperto a enum fechado vive no Form Request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('car_market_snapshots', function (Blueprint $table) {
            $table->string('dedup_hash', 40)->nullable()->after('scraped_at');
            $table->index('dedup_hash');
        });
    }

    public function down(): void
    {
        Schema::table('car_market_snapshots', function (Blueprint $table) {
            $table->dropIndex(['dedup_hash']);
            $table->dropColumn('dedup_hash');
        });
    }
};
