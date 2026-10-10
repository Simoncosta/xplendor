<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ficheiros (gestor de ficheiros): o catálogo único dos ficheiros de cada empresa. Só tabelas
 * e colunas novas. Nenhum ficheiro existente é movido, copiado ou apagado: o catálogo regista-os
 * onde estão (disco e caminho).
 *  · file_folders: as pastas (das pessoas e do sistema), só na base de dados; mudar o nome ou
 *    mover não copia ficheiros. Apagar manda para a reciclagem (deleted_at, purge_after).
 *  · stored_files: cada ficheiro (enviado nos Ficheiros, ou registado a partir de um módulo:
 *    source + source_ref). Os do sistema (is_system) não se apagam nem se movem aqui.
 *  · stored_file_shortcuts: "Guardar numa pasta" (o mesmo ficheiro aparece noutra pasta, sem cópia).
 *  · media_uploads.for_files / files_folder_id: o envio em partes começado nos Ficheiros.
 *  · media_assets.original_disk / original_path: uma media da Linha Editorial cujo original é um
 *    ficheiro que já existe noutro sítio ("Escolher dos Ficheiros"); só as variantes são novas,
 *    e a retenção nunca apaga esse original.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('file_folders')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('system_key', 80)->nullable(); // pastas do sistema (ex.: "editorial", "editorial:2026-10")
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->unsignedBigInteger('deleted_by_user_id')->nullable();
            $table->date('purge_after')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'parent_id']);
            $table->unique(['company_id', 'system_key']);
        });

        Schema::create('stored_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('folder_id')->nullable()->constrained('file_folders')->nullOnDelete();
            $table->string('name', 200);
            $table->string('extension', 16)->nullable();
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('disk', 30);
            $table->string('path', 500);
            $table->string('source', 40)->default('upload');   // upload, media, ocr, cobranca, comprovativo, ...
            $table->string('source_ref', 120)->nullable();      // ex.: "car_images:12:original" (único por empresa)
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('media_asset_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->boolean('is_system')->default(false);
            $table->unsignedBigInteger('uploaded_by_user_id')->nullable();
            $table->timestamp('missing_at')->nullable();        // o ficheiro saiu do módulo de origem
            $table->timestamp('deleted_at')->nullable();
            $table->unsignedBigInteger('deleted_by_user_id')->nullable();
            $table->date('purge_after')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'folder_id']);
            $table->index(['company_id', 'source']);
            $table->unique(['company_id', 'source_ref']);
        });

        Schema::create('stored_file_shortcuts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stored_file_id')->constrained('stored_files')->cascadeOnDelete();
            $table->foreignId('folder_id')->constrained('file_folders')->cascadeOnDelete();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->date('purge_after')->nullable();
            $table->timestamps();
            $table->unique(['stored_file_id', 'folder_id']);
        });

        Schema::table('media_assets', function (Blueprint $table) {
            $table->string('original_disk', 30)->nullable()->after('disk');
            $table->string('original_path', 500)->nullable()->after('original_disk');
        });

        // Envio em partes iniciado nos Ficheiros (as imagens e os vídeos usam o envio da Linha Editorial).
        Schema::table('media_uploads', function (Blueprint $table) {
            $table->boolean('for_files')->default(false)->after('media_asset_id');
            $table->unsignedBigInteger('files_folder_id')->nullable()->after('for_files');
        });
    }

    public function down(): void
    {
        Schema::table('media_uploads', fn (Blueprint $t) => $t->dropColumn(['for_files', 'files_folder_id']));
        Schema::table('media_assets', fn (Blueprint $t) => $t->dropColumn(['original_disk', 'original_path']));
        Schema::dropIfExists('stored_file_shortcuts');
        Schema::dropIfExists('stored_files');
        Schema::dropIfExists('file_folders');
    }
};
