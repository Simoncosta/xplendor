<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Linha Editorial, F3b (aditiva): media por versão.
 *  · media_assets: um ficheiro da empresa (imagem ou vídeo) no disco privado "media", com
 *    variantes (miniatura 320 e pré-visualização 1080 em WebP, capa do vídeo), dados
 *    técnicos e SHA-256 (deduplicação na empresa). Os mesmos campos previstos para a F2.
 *  · media_uploads: envio em partes de 8 MB, retomável.
 *  · editorial_post_version_media: os media de cada versão, por ordem, e a capa do vídeo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('impersonator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 10);                // image | video
            $table->string('disk', 20)->default('media');
            $table->string('dir');                     // company_{id}/{uuid}
            $table->string('original_name')->nullable();
            $table->string('extension', 10);
            $table->string('mime', 60);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('codec', 30)->nullable();
            $table->char('sha256', 64)->nullable();
            $table->json('variants')->nullable();      // {thumb: "thumb.webp", preview: "preview.webp", poster: "poster.jpg"}
            $table->string('status', 12)->default('processing'); // processing | ready | rejected
            $table->string('error', 300)->nullable();
            $table->timestamp('original_deleted_at')->nullable(); // retenção: o original saiu, as variantes ficam
            $table->timestamps();

            $table->index(['company_id', 'sha256'], 'media_assets_company_sha_idx');
            $table->index(['company_id', 'created_at'], 'media_assets_company_created_idx');
        });

        Schema::create('media_uploads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('impersonator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 10);
            $table->string('original_name');
            $table->string('extension', 10);
            $table->string('mime', 60);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedBigInteger('received_bytes')->default(0);
            $table->foreignId('media_asset_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('editorial_post_version_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('editorial_post_versions')->cascadeOnDelete();
            $table->foreignId('media_asset_id')->constrained('media_assets')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('role', 10)->default('item'); // item | cover
            $table->timestamps();

            $table->unique(['version_id', 'role', 'position'], 'ed_version_media_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('editorial_post_version_media');
        Schema::dropIfExists('media_uploads');
        Schema::dropIfExists('media_assets');
    }
};
