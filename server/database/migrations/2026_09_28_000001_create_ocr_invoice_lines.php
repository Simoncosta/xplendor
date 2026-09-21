<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — OCR faturas: LINHAS (1-N por fatura). Território novo (o XFIN
 * descartava as linhas). Valores monetários em CÊNTIMOS inteiros. Aditiva;
 * nome de índice CURTO explícito. Fase A: as linhas ficam como texto extraído
 * (sem matching a artigos — isso é fase futura).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ocr_invoice_lines')) {
            return;
        }

        Schema::create('ocr_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ocr_invoice_id')->constrained('ocr_invoices')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete(); // tenancy directa
            $table->unsignedInteger('position')->default(0);       // ordem de exibição
            $table->string('item')->nullable();
            $table->decimal('quantity', 12, 3)->nullable();
            $table->string('unit')->nullable();                    // un/kg/cx/L...
            $table->integer('unit_price_cents')->nullable();       // CÊNTIMOS
            $table->decimal('discount_pct', 5, 2)->nullable();     // 0..100
            $table->integer('line_total_cents')->nullable();       // CÊNTIMOS
            $table->unsignedTinyInteger('vat_rate')->nullable();   // 6|13|23
            $table->timestamps();

            $table->index(['ocr_invoice_id'], 'ocr_line_invoice_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ocr_invoice_lines');
    }
};
