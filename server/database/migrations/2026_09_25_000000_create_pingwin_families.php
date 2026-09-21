<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — PingWin Famílias (Fase 1, só leitura): árvore de famílias de artigos
 * por empresa. Guardadas FLAT (com parent_pingwin_id) — a árvore monta-se na
 * exibição (flat→nested por parent). Aditiva/idempotente; nome de índice CURTO
 * explícito (<64 chars — lição da migração do CoverManager).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pingwin_families')) {
            return;
        }

        Schema::create('pingwin_families', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('pingwin_id');                       // id da família no PingWin (chave)
            $table->string('description')->nullable();
            $table->string('parent_pingwin_id')->nullable();    // id da mãe (null/0 = raiz)
            $table->boolean('is_active')->default(true);        // = NOT deleted
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'pingwin_id'], 'pw_fam_company_pw_unique');
            $table->index(['company_id', 'parent_pingwin_id'], 'pw_fam_company_parent_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pingwin_families');
    }
};
