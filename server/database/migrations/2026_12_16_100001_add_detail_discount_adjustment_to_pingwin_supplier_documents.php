<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — F4: o total_products do PingWin é BRUTO (Σ qnt × preço, antes dos descontos de
 * linha) e as linhas vêm LÍQUIDAS. Provado na Yuko: Σ linhas = total_products −
 * detail_discount_value (= subtotal) e total = Σ linhas + IVA + adjustment (acerto). Guardam-se
 * os dois para a conferência e para explicar o total. Aditiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pingwin_supplier_documents', function (Blueprint $table) {
            $table->bigInteger('detail_discount_cents')->nullable()->after('total_tax_cents'); // Σ descontos de linha
            $table->bigInteger('adjustment_cents')->nullable()->after('detail_discount_cents');  // acerto
        });
    }

    public function down(): void
    {
        Schema::table('pingwin_supplier_documents', function (Blueprint $table) {
            $table->dropColumn(['detail_discount_cents', 'adjustment_cents']);
        });
    }
};
