<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Documentos de fornecedor do PingWin (F1): espelho da lista "Documentos" do BO
 * (browserdataset, DOCTYPE 2002). Upsert por (company_id, docheader_id). NUNCA se apagam:
 * as anulações chegam como estado (docstatus 8003 "Anulado"). Valores em cêntimos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pingwin_supplier_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('docheader_id', 30);
            $table->string('docconfig_id', 30);
            $table->string('doctype')->nullable();
            $table->string('document')->nullable();
            $table->string('entity_pingwin_id', 30)->nullable();
            $table->string('entity_name')->nullable();
            $table->string('fiscalname')->nullable();
            $table->string('tax_number', 30)->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('store_pingwin_id', 30)->nullable();
            $table->string('store_name')->nullable();
            $table->date('doc_date')->nullable();
            $table->date('fiscal_date')->nullable();
            $table->time('doc_time')->nullable();
            $table->bigInteger('total_cents')->default(0);
            $table->boolean('paid')->default(false);              // "Liquidado"
            $table->string('docstatus_id', 30)->nullable();
            $table->string('docstatus_description')->nullable();
            $table->string('employee_name')->nullable();           // "Lançado por"
            $table->string('docreference_number')->nullable();     // "Nº doc. referência" = nº da fatura do fornecedor
            $table->json('raw')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'docheader_id']);
            $table->index(['company_id', 'doc_date']);
            $table->index(['company_id', 'docconfig_id']);
            $table->index(['company_id', 'entity_pingwin_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pingwin_supplier_documents');
    }
};
