<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Site do consultor: os vídeos em destaque da página inicial (IDs do YouTube, por ordem) e as
 * fotografias das zonas de atuação (fundos e separadores do site). Aditiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultant_sites', function (Blueprint $table) {
            $table->json('featured_videos')->nullable();
            $table->json('zone_photos')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('consultant_sites', fn (Blueprint $t) => $t->dropColumn(['featured_videos', 'zone_photos']));
    }
};
