<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Site do consultor: a fotografia com fundo (página Sobre) e a imagem de partilha gerada a partir
 * dela (1200 × 630, para as redes). Aditiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultant_sites', function (Blueprint $table) {
            $table->string('about_photo_path', 255)->nullable();
            $table->string('share_image_path', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('consultant_sites', fn (Blueprint $t) => $t->dropColumn(['about_photo_path', 'share_image_path']));
    }
};
