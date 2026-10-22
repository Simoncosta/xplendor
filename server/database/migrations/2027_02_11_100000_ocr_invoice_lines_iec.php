<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faturas OCR: o IEC de uma coluna à parte que se soma ao valor da linha (ex.: bebidas). O valor
 * da linha (line_total_cents) já o inclui; esta coluna só guarda quanto foi, para o ecrã o mostrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ocr_invoice_lines', function (Blueprint $table) {
            $table->integer('iec_cents')->nullable()->after('gross_total_cents');
        });
    }

    public function down(): void
    {
        Schema::table('ocr_invoice_lines', function (Blueprint $table) {
            $table->dropColumn('iec_cents');
        });
    }
};
