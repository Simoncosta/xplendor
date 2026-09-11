<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DMS sub-fase 1c.2a — Categorias de Despesa (por empresa).
 *
 * Pré-requisito das despesas (1c.2b): cada despesa vai seleccionar uma categoria.
 * Scoped por company_id (cada stand gere as suas), como o modo IVA.
 *
 * `archived`: categorias com despesas associadas não podem ser eliminadas — só
 * arquivadas (somem da selecção de novas despesas mas mantêm-se para o histórico).
 * Ver ExpenseCategoryService.
 *
 * Aditiva e reversível. Entidade nova — não toca em dados existentes.
 * Arranque VAZIO: não se pré-popula nada aqui nem na criação da empresa; a
 * empresa importa as sugeridas ou cria do zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('name');                          // obrigatório
            $table->string('color', 20)->nullable();         // opcional — cor nos gráficos
            $table->boolean('archived')->default(false);     // arquivada vs activa

            $table->timestamps();

            $table->index(['company_id', 'archived']);
            $table->index(['company_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_categories');
    }
};
