<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Fatia 4 (fichas técnicas: criar, editar e apagar na PingWin):
 *  · recipe_writes: cada gravação na PingWin (uma ficha de cada vez, iniciada por uma pessoa): quem,
 *    quando, artigo, versão, o antes e o depois (JSON), o plano, o progresso por passos e o resultado
 *    confirmado por releitura. É também o "Histórico de alterações".
 *  · recipe_drafts: o rascunho de quem está a editar (fica na XPLENDOR até "Gravar na PingWin").
 * Aditiva e idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('recipe_writes')) {
            Schema::create('recipe_writes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('product_pingwin_id', 32);
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('operation', 32);                 // criar | nova_versao | corrigir | apagar_versao | apagar_ficha
                $table->string('bom_pingwin_id', 32)->nullable(); // a versão tocada (a nova, depois de gravar)
                $table->date('valid_from')->nullable();
                $table->json('before')->nullable();               // a ficha no espelho antes (todas as versões)
                $table->json('after')->nullable();                // o que se pediu (versão, lojas, linhas)
                $table->json('plan')->nullable();                 // as operações enviadas à PingWin
                $table->string('status', 16)->default('queued');  // queued | running | ok | mismatch | conflict | failed
                $table->json('progress')->nullable();             // passos: abrir, ler, versão, linhas n de m, lojas, gravar, confirmar
                $table->json('result')->nullable();               // referências, diferenças da releitura
                $table->text('error')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'product_pingwin_id']);
                $table->index(['company_id', 'status']);
            });
        }

        if (! Schema::hasTable('recipe_drafts')) {
            Schema::create('recipe_drafts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('product_pingwin_id', 32);
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->json('payload');
                $table->string('base_signature', 64)->nullable(); // o espelho de onde se partiu
                $table->timestamps();

                $table->unique(['company_id', 'product_pingwin_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_drafts');
        Schema::dropIfExists('recipe_writes');
    }
};
