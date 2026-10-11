<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — F2d: "Corrigir o IVA do artigo no PingWin" a partir de uma linha da fatura OCR.
 * vat_write_id = a escrita do artigo (pingwin_catalog_writes) pedida por essa linha, para a UI
 * mostrar o estado (a corrigir / ok / erro). Os vários códigos do fornecedor por artigo não
 * precisam de colunas: o supplier_article_map já aceita vários códigos para o mesmo artigo
 * (chave empresa + NIF + código), e o principal é o que está no PingWin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ocr_invoice_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('vat_write_id')->nullable()->after('supplier_code_write_id');
        });
    }

    public function down(): void
    {
        Schema::table('ocr_invoice_lines', function (Blueprint $table) {
            $table->dropColumn('vat_write_id');
        });
    }
};
