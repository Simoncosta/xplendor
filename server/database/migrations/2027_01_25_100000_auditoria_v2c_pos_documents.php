<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoria v2-C · C3: o espelho dos documentos de caixa do PingWin (anulações, consultas de mesa,
 * faturas-recibo, notas de crédito), lidos da lista "Documentos" (só leitura, um dia e um tipo de
 * cada vez). Só a linha da lista: o funcionário, a hora, a loja e o total.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pingwin_pos_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('docheader_id', 32);
            $t->string('docconfig_id', 32);
            $t->string('doctype', 120)->nullable();
            $t->string('document', 120)->nullable();
            $t->string('store_pingwin_id', 32)->nullable();
            $t->string('store_name', 160)->nullable();
            $t->date('doc_date')->nullable();
            $t->time('doc_time')->nullable();
            $t->bigInteger('total_cents')->default(0);
            $t->string('employee_pingwin_id', 32)->nullable();
            $t->string('employee_name', 160)->nullable();
            $t->string('docstatus_id', 32)->nullable();
            $t->string('docstatus_description', 120)->nullable();
            $t->string('accumulator', 64)->nullable();
            $t->json('raw')->nullable();
            $t->timestamp('first_seen_at')->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'docheader_id']);
            $t->index(['company_id', 'docconfig_id', 'doc_date'], 'pos_docs_type_date');
            $t->index(['company_id', 'employee_pingwin_id', 'doc_date'], 'pos_docs_employee_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pingwin_pos_documents');
    }
};
