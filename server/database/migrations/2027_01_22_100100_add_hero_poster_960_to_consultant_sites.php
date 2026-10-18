<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Site do consultor: a capa do vídeo do topo também em 960 px, para o telemóvel (a de 1920 px
 * pesava no carregamento da página inicial). Aditiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultant_sites', function (Blueprint $table) {
            $table->string('hero_poster_960_path', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('consultant_sites', fn (Blueprint $t) => $t->dropColumn('hero_poster_960_path'));
    }
};
