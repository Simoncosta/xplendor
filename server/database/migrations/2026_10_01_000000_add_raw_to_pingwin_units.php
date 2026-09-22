<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — guarda o OBJETO COMPLETO (raw) de cada unidade do PingWin. Necessário
 * para EDITAR/ANULAR: o PingWin grava o objeto TODO (todos os campos: key, tare,
 * frac_unit, warn_maxsale_qnt, print_label, …), não só os alterados. Guardar o raw
 * ao sincronizar/criar garante que reenviamos o objeto completo sem perder campos.
 * Aditiva/idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pingwin_units') || Schema::hasColumn('pingwin_units', 'raw')) {
            return;
        }
        Schema::table('pingwin_units', function (Blueprint $table) {
            $table->json('raw')->nullable()->after('external_measure'); // objeto completo do PingWin
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('pingwin_units') && Schema::hasColumn('pingwin_units', 'raw')) {
            Schema::table('pingwin_units', function (Blueprint $table) {
                $table->dropColumn('raw');
            });
        }
    }
};
