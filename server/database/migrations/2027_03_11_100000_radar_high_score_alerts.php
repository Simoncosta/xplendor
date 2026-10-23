<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bússola 7: os avisos dos sinais de nota alta. Só aditiva:
 *  · alerts.kind (vazio nos avisos de hoje): o tipo de aviso, para cada pessoa poder esconder um tipo;
 *  · radar_signals.alerted_at: quando o sinal gerou aviso (no máximo um por dia e por empresa);
 *  · user_notification_preferences: as preferências de cada pessoa (ligado por omissão).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->string('kind', 40)->nullable()->after('type');
        });
        Schema::table('radar_signals', function (Blueprint $table) {
            $table->dateTime('alerted_at')->nullable();
        });
        Schema::create('user_notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->boolean('radar_high_score')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notification_preferences');
        Schema::table('radar_signals', fn (Blueprint $t) => $t->dropColumn('alerted_at'));
        Schema::table('alerts', fn (Blueprint $t) => $t->dropColumn('kind'));
    }
};
