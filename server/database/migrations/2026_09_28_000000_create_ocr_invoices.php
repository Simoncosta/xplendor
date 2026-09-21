<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — OCR de faturas de fornecedor (Fase A): a fatura lida pela IA, para
 * VALIDAÇÃO HUMANA. NÃO escreve no PingWin (synced_to_pingwin fica false, pronto
 * para a Fase B). Valores (totais) vivem em ocr_invoice_summary; linhas em
 * ocr_invoice_lines. Guarda model + prompt_version (ouro para depurar o prompt).
 * Aditiva/idempotente; nomes de índice CURTOS explícitos (<64 chars).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ocr_invoices')) {
            return;
        }

        Schema::create('ocr_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // Fornecedor: ligação (opcional) ao fornecedor já sincronizado + o que a IA leu.
            $table->unsignedBigInteger('supplier_id')->nullable(); // pingwin_suppliers.id (sem FK dura)
            $table->string('supplier_name')->nullable();
            $table->string('supplier_nif')->nullable();
            $table->string('number')->nullable();                  // nº da fatura
            $table->date('issue_date')->nullable();                // data de emissão
            $table->string('image_path');                          // caminho no storage (imagem/pdf original)
            $table->string('image_mime')->nullable();
            $table->string('model')->nullable();                   // ex.: gpt-4o-mini
            $table->string('prompt_version')->nullable();          // ex.: b2b-v1
            $table->unsignedTinyInteger('confidence')->default(0); // 0..100 (heurística de completude)
            $table->string('status')->default('processing');       // processing|por_validar|validada|erro
            $table->text('error_message')->nullable();
            $table->boolean('synced_to_pingwin')->default(false);  // Fase B (futura) — fica false
            $table->timestamps();

            $table->index(['company_id', 'status'], 'ocr_inv_company_status_idx');
            $table->index(['company_id', 'created_at'], 'ocr_inv_company_created_idx');
            $table->index(['supplier_id'], 'ocr_inv_supplier_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ocr_invoices');
    }
};
