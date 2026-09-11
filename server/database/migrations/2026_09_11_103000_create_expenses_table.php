<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DMS sub-fase 1c.2b — Despesas (fecha o triângulo despesa ↔ veículo ↔ fornecedor).
 *
 * Scoped por company_id. FKs opcionais: uma despesa pode não ter fornecedor,
 * não ter viatura e não ter categoria (categoria opcional — decisão do Simon;
 * os gráficos mostram "Sem categoria"). Nome da tabela `expenses` — o guard
 * de `ExpenseCategoryService::expensesCount()` já procura por este nome, logo a
 * regra de arquivo das categorias auto-activa.
 *
 * `is_paid` + `paid_at` para fluxo de caixa (pago/não-pago com data de pagamento).
 * `archived`: despesa com vínculo (fornecedor/viatura/categoria) não se elimina —
 * arquiva-se, mantendo o histórico. Ver ExpenseService.
 *
 * FKs com nullOnDelete: apagar viatura/fornecedor/categoria (quando permitido)
 * não apaga a despesa — só desliga o vínculo (a despesa é histórico financeiro).
 *
 * Aditiva e reversível. Entidade nova.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('description');
            $table->decimal('amount', 12, 2);
            $table->date('date');

            $table->foreignId('expense_category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('car_id')->nullable()->constrained()->nullOnDelete();

            $table->boolean('is_paid')->default(false);
            $table->date('paid_at')->nullable();

            $table->boolean('archived')->default(false);
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'date']);
            $table->index(['company_id', 'is_paid']);
            $table->index(['company_id', 'archived']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
