<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ficha da viatura (A4): a nota de cada empresa no rodapé, por cima do aviso legal (por exemplo,
 * a oficina ou o financiamento), até 250 caracteres. Vazia, não aparece. Só acrescenta, e nasce
 * vazia em todas as empresas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('print_note', 250)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('print_note'));
    }
};
