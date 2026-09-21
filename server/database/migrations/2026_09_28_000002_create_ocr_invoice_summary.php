<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — OCR faturas: SUMÁRIO (1-1 por fatura). A cadeia completa B2B em
 * CÊNTIMOS inteiros (total mercadorias, desconto comercial, base, IVA, retenção,
 * desconto financeiro, total) + IVA por taxa (json). Aditiva; índice curto.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ocr_invoice_summary')) {
            return;
        }

        Schema::create('ocr_invoice_summary', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ocr_invoice_id')->constrained('ocr_invoices')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->integer('goods_total_cents')->default(0);          // total mercadorias
            $table->integer('commercial_discount_cents')->default(0);  // desconto comercial
            $table->integer('taxable_base_cents')->default(0);         // base tributável
            $table->integer('vat_total_cents')->default(0);            // valor IVA total
            $table->integer('withholding_cents')->default(0);          // retenção na fonte
            $table->integer('financial_discount_cents')->default(0);   // desconto financeiro
            $table->integer('total_cents')->default(0);                // total da fatura
            $table->json('vat_breakdown')->nullable();                 // [{rate,base_cents,vat_cents}]
            $table->timestamps();

            $table->unique(['ocr_invoice_id'], 'ocr_sum_invoice_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ocr_invoice_summary');
    }
};
