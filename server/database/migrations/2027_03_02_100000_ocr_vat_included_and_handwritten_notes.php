<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faturas OCR: talões com os preços com IVA incluído (convertidos para sem IVA; o valor original com IVA
 * fica em cada linha) e as notas escritas à mão no documento (tal como estão).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ocr_invoices', function (Blueprint $table) {
            $table->boolean('prices_include_vat')->default(false)->after('doc_type');
            $table->text('handwritten_notes')->nullable()->after('prices_include_vat');
        });
        Schema::table('ocr_invoice_lines', function (Blueprint $table) {
            $table->integer('vat_included_cents')->nullable()->after('iec_cents');
        });
    }

    public function down(): void
    {
        Schema::table('ocr_invoice_lines', function (Blueprint $table) {
            $table->dropColumn('vat_included_cents');
        });
        Schema::table('ocr_invoices', function (Blueprint $table) {
            $table->dropColumn(['prices_include_vat', 'handwritten_notes']);
        });
    }
};
