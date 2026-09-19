<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — CoverManager Etapa 2 (ticket médio). Flag por empresa: calcular o
 * ticket médio com as PESSOAS do CoverManager (guests_total). Default TRUE
 * (decisão do Simon — o PingWin não tem nº de pessoas fiável). Aditiva/reversível.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('cm_avg_ticket_enabled')->default(true)->after('subscription_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('cm_avg_ticket_enabled');
        });
    }
};
