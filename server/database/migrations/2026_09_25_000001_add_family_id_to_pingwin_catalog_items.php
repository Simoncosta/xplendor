<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — liga os artigos às famílias: coluna family_id (nullable) em
 * pingwin_catalog_items, preenchida após o sync das famílias (por family_pingwin_id
 * → pingwin_families). NULLABLE → artigo órfão (família inexistente) fica sem
 * família, sem rebentar. Aditiva/idempotente; nome de índice CURTO explícito.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pingwin_catalog_items') || Schema::hasColumn('pingwin_catalog_items', 'family_id')) {
            return;
        }

        Schema::table('pingwin_catalog_items', function (Blueprint $table) {
            // Sem FK constraint dura: o religar é por family_pingwin_id e órfãos
            // ficam null; evita falhas de ordem/limpeza. Índice curto explícito.
            $table->unsignedBigInteger('family_id')->nullable()->after('family_pingwin_id');
            $table->index(['company_id', 'family_id'], 'pw_cat_item_company_famid_idx');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('pingwin_catalog_items') && Schema::hasColumn('pingwin_catalog_items', 'family_id')) {
            Schema::table('pingwin_catalog_items', function (Blueprint $table) {
                $table->dropIndex('pw_cat_item_company_famid_idx');
                $table->dropColumn('family_id');
            });
        }
    }
};
