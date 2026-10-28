<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faturas de despesas gerais (fornecedores de serviços, como os CTT): por empresa e fornecedor, o
 * artigo de despesa de cada taxa de IVA (escolhido entre os artigos que já existem; nada se cria no
 * PingWin). As linhas com valor das faturas desse fornecedor ligam-se sozinhas ao artigo da sua taxa
 * e juntam-se numa linha por taxa. Só o root marca. Só acrescenta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('general_expense_suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->json('articles'); // {"23": id do catálogo, "0": id do catálogo, …}
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'supplier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('general_expense_suppliers');
    }
};
