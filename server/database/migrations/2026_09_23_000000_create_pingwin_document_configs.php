<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — PingWin Documentos (Fase 1, só leitura): tipos de documento
 * (Definições→Documentos) por empresa. Aditiva/idempotente; nomes de índice
 * CURTOS explícitos (<64 chars — lição da migração do CoverManager).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pingwin_document_configs')) {
            return;
        }

        Schema::create('pingwin_document_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');                 // id do documento no PingWin
            $table->string('code')->nullable();
            $table->string('description')->nullable();
            $table->string('entitytype')->nullable();      // Cliente/Fornecedor/Armazém…
            $table->string('fiscaltype')->nullable();
            $table->string('fiscaltype_description')->nullable();
            $table->boolean('deleted')->default(false);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'external_id'], 'pw_doc_cfg_company_ext_unique');
            $table->index(['company_id'], 'pw_doc_cfg_company_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pingwin_document_configs');
    }
};
