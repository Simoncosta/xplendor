<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Linha Editorial: "gancho" de conteúdo (abordagem sugerida) por âncora.
 * Coluna aditiva `suggestion` (nullable) em content_anchors E editorial_own_anchors, para
 * herdadas e próprias poderem carregar a ideia editorial da ocasião (ex.: "Dia do Pai →
 * primeiro carro do teu pai"). As 18 fiáveis existentes ficam null (feriados não precisam).
 * Aditiva/reversível; nada mais em content_anchors muda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_anchors', function (Blueprint $table) {
            $table->text('suggestion')->nullable()->after('notes');
        });
        Schema::table('editorial_own_anchors', function (Blueprint $table) {
            $table->text('suggestion')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('content_anchors', function (Blueprint $table) {
            $table->dropColumn('suggestion');
        });
        Schema::table('editorial_own_anchors', function (Blueprint $table) {
            $table->dropColumn('suggestion');
        });
    }
};
