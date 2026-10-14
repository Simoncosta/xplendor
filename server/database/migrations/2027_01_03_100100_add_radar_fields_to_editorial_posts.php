<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Radar (Bússola Master, R2): o "Reagir" cria uma ideia com o gancho, as fontes e o prazo, sem
 * texto. Cinco colunas opcionais; as publicações que já existem ficam com elas vazias.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('editorial_posts', function (Blueprint $table) {
            $table->text('brief')->nullable();
            $table->json('sources')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('origin', 20)->nullable();
            $table->string('origin_ref', 160)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('editorial_posts', function (Blueprint $table) {
            $table->dropColumn(['brief', 'sources', 'valid_until', 'origin', 'origin_ref']);
        });
    }
};
