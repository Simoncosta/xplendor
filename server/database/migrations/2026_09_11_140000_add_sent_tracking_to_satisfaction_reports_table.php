<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DMS Pós-venda (Incremento 5) — regista quando/por onde o link foi enviado ao
 * cliente, para o stand saber se já enviou (e evitar duplicar sem querer).
 * Aditiva e reversível.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('satisfaction_reports', function (Blueprint $table) {
            $table->timestamp('sent_at')->nullable()->after('submitted_at');
            $table->string('sent_channel', 20)->nullable()->after('sent_at'); // email | whatsapp
        });
    }

    public function down(): void
    {
        Schema::table('satisfaction_reports', function (Blueprint $table) {
            $table->dropColumn(['sent_at', 'sent_channel']);
        });
    }
};
