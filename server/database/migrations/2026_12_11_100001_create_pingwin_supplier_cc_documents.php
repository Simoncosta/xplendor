<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Conta corrente de fornecedor (S1): espelho dos documentos de conta corrente
 * (POST /service/suppliercc/*\/ccdocuments). Upsert por (company_id, docheader_id); a sync
 * apaga, por fornecedor, os que deixaram de vir. Valores em CÊNTIMOS int.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pingwin_supplier_cc_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->string('entity_pingwin_id', 30);
            $table->string('docheader_id', 30);
            $table->string('docconfig_id', 30);
            $table->string('doctype')->nullable();
            $table->string('document')->nullable();
            $table->date('doc_date')->nullable();
            $table->date('due_date')->nullable();
            $table->date('fiscal_date')->nullable();
            $table->bigInteger('total_cents')->default(0);
            $table->bigInteger('total_paid_cents')->default(0);
            $table->bigInteger('topay_cents')->default(0);
            $table->bigInteger('suspended_cents')->default(0);
            $table->boolean('paid')->default(false);
            $table->tinyInteger('ca_signal')->default(1);   // 1 = fatura/crédito · -1 = liquidação/NC
            $table->boolean('iscredit')->default(false);
            $table->boolean('isdebit')->default(false);
            $table->string('docstatus_description')->nullable();
            $table->string('docreference_number')->nullable(); // nº da fatura do fornecedor
            $table->string('store_pingwin_id', 30)->nullable();
            $table->string('store_name')->nullable();
            $table->json('raw')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'docheader_id']);
            $table->index(['company_id', 'supplier_id']);
            $table->index(['company_id', 'docconfig_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pingwin_supplier_cc_documents');
    }
};
