<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DMS Fase 1a — interruptor "modo IVA" por empresa.
 *
 * Nem todos os clientes trabalham com IVA (uns passam factura com NIF, outros
 * vendem em dinheiro sem factura). O regime de IVA por viatura só faz sentido
 * quando a empresa o tem ligado — este flag comanda essa condicional no form.
 *
 * Aditiva e reversível. Default FALSE: quem precisa liga; quem não sabe não é
 * empurrado para IVA.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('uses_vat')
                ->default(false)
                ->after('vat_value')
                ->comment('Empresa trabalha com IVA (liga o regime de IVA por viatura)');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('uses_vat');
        });
    }
};
