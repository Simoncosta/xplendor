<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Generaliza o sino (alerts) para notificações de SISTEMA sem viatura
 * (ex.: "Dados de vendas atualizados" do PingWin). Aditiva:
 *  · car_id passa a NULLABLE (um alerta de sistema não tem viatura);
 *  · detail_path (para onde o alerta aponta quando não é uma ficha de viatura).
 * Os alertas de viatura existentes continuam iguais (car_id presente).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->foreignId('car_id')->nullable()->change();
            $table->string('detail_path')->nullable()->after('message');
        });
    }

    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->dropColumn('detail_path');
            // car_id volta a NOT NULL (só seguro se não houver alertas de sistema).
            $table->foreignId('car_id')->nullable(false)->change();
        });
    }
};
