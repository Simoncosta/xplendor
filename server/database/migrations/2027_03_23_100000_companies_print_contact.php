<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ficha da viatura (A4): o contacto comercial da empresa. Com o telefone preenchido, a ficha
 * mostra "{designação}: {telefone}" em vez do vendedor; vazio, fica como antes. Só acrescenta,
 * e nasce vazio em todas as empresas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('print_contact_label', 60)->nullable();
            $table->string('print_contact_phone', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn(['print_contact_label', 'print_contact_phone']));
    }
};
