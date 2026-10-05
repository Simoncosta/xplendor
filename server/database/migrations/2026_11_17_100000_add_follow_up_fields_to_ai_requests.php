<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedidos à IA sem prender o ecrã (aditiva):
 *  · dismissed_at: o utilizador usou ou descartou o resultado; deixa de ficar "à espera".
 *  · stalled_logged_at: o pedido passou 3 minutos sem resposta e o caso ficou registado
 *    (uma só vez por pedido).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_requests', function (Blueprint $table) {
            $table->timestamp('dismissed_at')->nullable()->after('error_message');
            $table->timestamp('stalled_logged_at')->nullable()->after('dismissed_at');
        });
    }

    public function down(): void
    {
        Schema::table('ai_requests', function (Blueprint $table) {
            $table->dropColumn(['dismissed_at', 'stalled_logged_at']);
        });
    }
};
