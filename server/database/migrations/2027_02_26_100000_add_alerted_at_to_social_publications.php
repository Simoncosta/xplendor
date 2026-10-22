<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F2.7 · Fiabilidade: quando foi dado o aviso urgente de uma publicação automática (falhou de vez,
 * ou está "a publicar" há mais de 15 minutos). Um aviso por publicação; volta a vazio ao reagendar.
 *
 * ADITIVA: só uma coluna nova, nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('social_publications', 'alerted_at')) {
            return;
        }

        Schema::table('social_publications', function (Blueprint $table) {
            $table->timestamp('alerted_at')->nullable()->after('cancelled_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('social_publications', 'alerted_at')) {
            return;
        }

        Schema::table('social_publications', function (Blueprint $table) {
            $table->dropColumn('alerted_at');
        });
    }
};
