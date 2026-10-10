<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R2: o registo da migração dos ficheiros para o R2 (php artisan storage:migrate-to-r2). Uma
 * linha por ficheiro: de onde, para onde, tamanho e SHA-256 confirmados, e quando foi apagado o
 * local (php artisan storage:purge-local). Torna a migração retomável e idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_migration_items', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 30);          // media, avatar, cobranca, ocr
            $table->string('source_disk', 30);
            $table->string('target_disk', 30);
            $table->string('path', 500);
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->string('status', 20);         // copiado, verificado, falhou, em_falta
            $table->string('error', 500)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('local_deleted_at')->nullable();
            $table->timestamps();
            $table->unique(['source_disk', 'target_disk', 'path'], 'storage_migration_items_unique');
            $table->index(['status', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_migration_items');
    }
};
