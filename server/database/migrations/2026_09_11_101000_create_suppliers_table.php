<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DMS sub-fase 1c.1 — entidade Fornecedor.
 *
 * Base para as despesas de viatura (sub-fase seguinte) e, no futuro, documentos.
 * Scoped por company_id (cada stand tem os seus fornecedores).
 *
 * MORADA: reutiliza EXACTAMENTE o mesmo padrão da empresa (companies) — campos
 * inline `address`/`postal_code` + FKs para as tabelas lookup partilhadas
 * districts/municipalities/parishes. Não há tabela `addresses` no projecto.
 *
 * Aditiva e reversível. Entidade nova — não toca em dados existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('name');                 // único obrigatório
            $table->string('nif')->nullable();      // contribuinte
            $table->string('phone')->nullable();
            $table->string('email')->nullable();

            // Morada — mesmo padrão de companies (inline + FKs lookup).
            $table->string('address')->nullable();
            $table->string('postal_code')->nullable();
            $table->foreignId('district_id')->nullable()->constrained();
            $table->foreignId('municipality_id')->nullable()->constrained();
            $table->foreignId('parish_id')->nullable()->constrained();

            $table->string('iban')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
