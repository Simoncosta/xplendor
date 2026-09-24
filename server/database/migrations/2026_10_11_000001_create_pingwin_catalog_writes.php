<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — AUDITORIA/estado de uma escrita de ARTIGO no PingWin, à imagem de
 * pingwin_unit_creations: quem/quando/o quê + resultado (pingwin_id ou erro real),
 * e alvo de POLLING da UI (a escrita corre no worker). Scoped por company_id.
 *
 * ⚠️ Ainda NÃO é usada neste incremento (Etapa 1a é só leitura). Fica preparada
 * para a Etapa 1b (create/edit/anular). Snapshot leve (code/description) por agora;
 * o snapshot completo do payload será fixado na 1b com o HAR de gravar.
 *
 * Convenções espelhadas: action (criar|editar|anular), status default 'a_criar'
 * (a_criar|ok|erro), índice curto (company_id, status). Aditiva/idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pingwin_catalog_writes')) {
            return;
        }

        Schema::create('pingwin_catalog_writes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();        // quem escreveu (auditoria)
            $table->string('action');                                  // criar|editar|anular
            $table->unsignedBigInteger('catalog_item_id')->nullable(); // artigo local (editar/anular)
            // Snapshot leve do que foi enviado (o completo chega na 1b).
            $table->string('code')->nullable();
            $table->string('description')->nullable();
            $table->string('status')->default('a_criar');              // a_criar|ok|erro
            $table->string('pingwin_id')->nullable();                  // id devolvido/afetado
            $table->text('error_message')->nullable();                 // motivo REAL se falhar
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status'], 'pw_cat_write_company_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pingwin_catalog_writes');
    }
};
