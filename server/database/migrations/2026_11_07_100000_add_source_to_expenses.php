<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Despesas automáticas (gasto Meta por viatura).
 *
 *   source           → 'manual' (por omissão, tudo o que já existe) | 'meta_ads';
 *   source_key       → chave do upsert da projeção, única por empresa
 *                      (ex.: meta:car:89:2026-10, meta:general:2026-10);
 *   source_synced_at → última vez que a projeção escreveu a linha.
 * Aditiva: as despesas existentes ficam 'manual'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('source', 20)->default('manual')->after('company_id');
            $table->string('source_key', 100)->nullable()->after('source');
            $table->timestamp('source_synced_at')->nullable()->after('source_key');

            $table->unique(['company_id', 'source_key'], 'expenses_company_source_key_unique');
            $table->index(['company_id', 'source'], 'expenses_company_source_idx');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropUnique('expenses_company_source_key_unique');
            $table->dropIndex('expenses_company_source_idx');
            $table->dropColumn(['source', 'source_key', 'source_synced_at']);
        });
    }
};
