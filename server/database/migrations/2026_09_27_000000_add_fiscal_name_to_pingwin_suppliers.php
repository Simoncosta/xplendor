<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Fornecedores PingWin: acrescenta o NOME FISCAL (fiscalname do HAR),
 * que ficou por mapear. A morada (address), localidade (city) e código postal
 * (postal_code) já tinham coluna — só o mapeamento apontava para chaves erradas
 * (corrigido no PingwinService). Aditiva/idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pingwin_suppliers') || Schema::hasColumn('pingwin_suppliers', 'fiscal_name')) {
            return;
        }

        Schema::table('pingwin_suppliers', function (Blueprint $table) {
            $table->string('fiscal_name')->nullable()->after('name'); // nome fiscal (fiscalname)
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('pingwin_suppliers') && Schema::hasColumn('pingwin_suppliers', 'fiscal_name')) {
            Schema::table('pingwin_suppliers', function (Blueprint $table) {
                $table->dropColumn('fiscal_name');
            });
        }
    }
};
