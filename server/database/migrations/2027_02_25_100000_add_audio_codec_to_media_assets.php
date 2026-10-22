<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F2.3 · Ficheiros para a Meta: o som do vídeo (o Instagram e o Facebook pedem H.264 com AAC).
 * Preenchido pelo ffprobe ao programar uma publicação automática: "aac", outro codec, ou "none"
 * quando o vídeo não tem som. Vazio = ainda não verificado.
 *
 * ADITIVA: só uma coluna nova, nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('media_assets', 'audio_codec')) {
            return;
        }

        Schema::table('media_assets', function (Blueprint $table) {
            $table->string('audio_codec', 20)->nullable()->after('codec');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('media_assets', 'audio_codec')) {
            return;
        }

        Schema::table('media_assets', function (Blueprint $table) {
            $table->dropColumn('audio_codec');
        });
    }
};
