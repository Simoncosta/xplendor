<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O índice de article_unit_changes com nome curto, se faltar: a primeira versão da migração anterior
 * usava o nome automático, com mais de 64 carateres, e o MariaDB recusava-o (a tabela ficava sem ele).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('article_unit_changes') && ! Schema::hasIndex('article_unit_changes', 'auc_company_product_changed_idx')) {
            Schema::table('article_unit_changes', function (Blueprint $table) {
                $table->index(['company_id', 'product_pingwin_id', 'changed_at'], 'auc_company_product_changed_idx');
            });
        }
    }

    public function down(): void
    {
        // O índice pertence à tabela (a migração anterior apaga-a).
    }
};
