<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — F1-2 do marketing da restauração: histórico das vendas por artigo.
 *  · pingwin_locations.sales_first_month: primeiro mês com vendas (relatório anual);
 *    sales_since: primeiro dia com vendas (das vendas por artigo, no fim do histórico);
 *    sales_start_checked_at: quando se detetou; history_complete_at: fim da importação.
 *  · pingwin_item_sales_days.reads_count: leituras de cada dia (os dias marcados voltam a
 *    ler-se até 3 vezes).
 * Aditiva e reversível.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pingwin_locations', function (Blueprint $table) {
            $table->date('sales_first_month')->nullable()->after('opened_on');
            $table->date('sales_since')->nullable()->after('sales_first_month');
            $table->timestamp('sales_start_checked_at')->nullable()->after('sales_since');
            $table->timestamp('history_complete_at')->nullable()->after('sales_start_checked_at');
        });

        Schema::table('pingwin_item_sales_days', function (Blueprint $table) {
            $table->unsignedSmallInteger('reads_count')->default(1)->after('daily_net_cents');
        });
    }

    public function down(): void
    {
        Schema::table('pingwin_item_sales_days', function (Blueprint $table) {
            $table->dropColumn('reads_count');
        });
        Schema::table('pingwin_locations', function (Blueprint $table) {
            $table->dropColumn(['sales_first_month', 'sales_since', 'sales_start_checked_at', 'history_complete_at']);
        });
    }
};
