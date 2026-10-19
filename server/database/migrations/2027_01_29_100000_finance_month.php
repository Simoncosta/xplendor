<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finanças › Mês:
 *  · finance_classifications: a classificação manual dos gastos "Por classificar", guardada na
 *    XPLENDOR (nada se escreve no PingWin), por documento ("doc:<docheader_id>") ou para sempre
 *    para um fornecedor ("sup:<entity_pingwin_id>");
 *  · supplier_document_closes: cada "Fechar no PingWin" de um documento de fornecedor em Aberto
 *    (também os lançados diretamente no PingWin): quem, quando, o documento, o estado antes e
 *    depois e o resultado; um lote ("Fechar os selecionados") fecha um de cada vez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_classifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('scope', 12);                        // documento | fornecedor
            $t->string('key', 60);                          // doc:<docheader_id> | sup:<entity_pingwin_id>
            $t->string('docheader_id', 32)->nullable();
            $t->string('entity_pingwin_id', 32)->nullable();
            $t->string('entity_name', 160)->nullable();
            $t->string('group', 20);                        // comida | bebida | pessoal | fse | outros | fora
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['company_id', 'key']);
        });

        Schema::create('supplier_document_closes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('batch', 36);
            $t->unsignedTinyInteger('position')->default(0);
            $t->string('docheader_id', 32);
            $t->string('docconfig_id', 32)->nullable();
            $t->string('document', 120)->nullable();
            $t->string('entity_name', 160)->nullable();
            $t->bigInteger('total_cents')->default(0);
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('status', 20)->default('pendente');  // pendente | ok | erro | erro_confirmacao | nao_feito
            $t->string('status_before', 10)->nullable();
            $t->string('status_after', 10)->nullable();
            $t->string('error', 1000)->nullable();
            $t->json('result')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'batch']);
            $t->index(['company_id', 'docheader_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_document_closes');
        Schema::dropIfExists('finance_classifications');
    }
};
