<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DMS — liga a venda ao Cliente (decisão (a) do Simon).
 *
 * customer_id FK NULLABLE, nullOnDelete. NÃO se tocam os campos buyer_* — as
 * vendas antigas ficam como estão (buyer_*, customer_id NULL). Vendas novas
 * passam a usar o cliente. A ficha lê com retro-compat (customer se existir,
 * senão buyer_* legado). A migração dos dados antigos (opção b) fica para o
 * futuro (comando idempotente com dedupe por NIF) — NÃO se faz aqui.
 *
 * Aditiva, reversível.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('car_sales', function (Blueprint $table) {
            $table->foreignId('customer_id')
                ->nullable()
                ->after('company_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('car_sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
        });
    }
};
