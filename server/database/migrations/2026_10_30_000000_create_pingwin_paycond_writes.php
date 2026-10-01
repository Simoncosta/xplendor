<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — AUDITORIA/estado de uma ESCRITA de condição de pagamento no PingWin
 * (Fatia 2a: criar), à imagem de pingwin_catalog_writes: quem/quando/o quê + resultado
 * (pingwin_id ou erro real), e alvo de POLLING da UI (a escrita corre no worker).
 *
 * discount em % (NÃO cêntimos); days int; tbdocs_unlinked = docconfig_id DESMARCADOS.
 * Aditiva/idempotente; índice curto (company_id, status).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pingwin_paycond_writes')) {
            return;
        }

        Schema::create('pingwin_paycond_writes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();   // quem escreveu (auditoria)
            $table->string('action');                             // criar (2b/2c acrescentam editar/anular)
            $table->string('paycond_id')->nullable();             // id afetado (editar/anular; criar preenche no fim)
            $table->string('code')->nullable();
            $table->string('description')->nullable();
            $table->decimal('discount', 8, 2)->nullable();        // % (não cêntimos)
            $table->integer('days')->nullable();
            $table->json('tbdocs_unlinked')->nullable();          // docconfig_id desmarcados (deleted:1)
            $table->string('status')->default('a_criar');         // a_criar|ok|erro
            $table->string('pingwin_id')->nullable();             // id FINAL devolvido pelo servidor
            $table->text('error_message')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status'], 'pw_paycond_write_company_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pingwin_paycond_writes');
    }
};
