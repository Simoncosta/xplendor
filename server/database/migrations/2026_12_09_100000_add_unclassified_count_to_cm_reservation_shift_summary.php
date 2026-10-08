<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — mapa dos códigos de estado do CoverManager (documents/PINGWIN-F1-DESENHO.md §11).
 *  · cm_reservation_shift_summary.unclassified_count: reservas com um código fora do mapa,
 *    fora das válidas e das anuladas, para nunca serem contadas às cegas.
 * Aditiva e reversível.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cm_reservation_shift_summary', function (Blueprint $table) {
            $table->unsignedInteger('unclassified_count')->default(0)->after('no_show_count');
        });
    }

    public function down(): void
    {
        Schema::table('cm_reservation_shift_summary', function (Blueprint $table) {
            $table->dropColumn('unclassified_count');
        });
    }
};
