<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — mais campos do form de unidade do PingWin no snapshot de auditoria:
 * frac_unit (unidade fracionária, checkbox) + warn_maxsale_qnt (qnt. máx. venda).
 * Aditiva/idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pingwin_unit_creations')) {
            return;
        }
        Schema::table('pingwin_unit_creations', function (Blueprint $table) {
            if (! Schema::hasColumn('pingwin_unit_creations', 'frac_unit')) {
                $table->boolean('frac_unit')->nullable()->after('external_measure');
            }
            if (! Schema::hasColumn('pingwin_unit_creations', 'warn_maxsale_qnt')) {
                $table->decimal('warn_maxsale_qnt', 16, 5)->nullable()->after('frac_unit');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('pingwin_unit_creations')) {
            Schema::table('pingwin_unit_creations', function (Blueprint $table) {
                foreach (['frac_unit', 'warn_maxsale_qnt'] as $c) {
                    if (Schema::hasColumn('pingwin_unit_creations', $c)) {
                        $table->dropColumn($c);
                    }
                }
            });
        }
    }
};
