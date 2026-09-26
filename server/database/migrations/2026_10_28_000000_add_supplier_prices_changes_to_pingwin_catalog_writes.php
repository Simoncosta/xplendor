<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — PingWin › tab Compras (C2): o registo de escrita (editar artigo) passa a
 * poder transportar as mudanças de linhas de fornecedor (create/update/delete) até ao job.
 * Coluna aditiva json nullable — se null/vazia, o editar comporta-se EXATAMENTE como hoje.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('pingwin_catalog_writes', 'supplier_prices_changes')) {
            return;
        }
        Schema::table('pingwin_catalog_writes', function (Blueprint $table) {
            $table->json('supplier_prices_changes')->nullable()->after('purchaseprice_cents');
        });
    }

    public function down(): void
    {
        Schema::table('pingwin_catalog_writes', function (Blueprint $table) {
            $table->dropColumn('supplier_prices_changes');
        });
    }
};
