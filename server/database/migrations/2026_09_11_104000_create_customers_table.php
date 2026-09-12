<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DMS — entidade Cliente (pré-requisito da Fase 3: documentos de venda).
 *
 * Scoped por company_id. Molde do Supplier (1c.1). MORADA reutiliza os 5 campos
 * partilhados (inline + FKs lookup districts/municipalities/parishes — não há
 * tabela addresses no projecto).
 *
 * Campos legais típicos de um contrato de compra e venda já incluídos (decisão
 * do Simon, para não remexer na Fase 3). A lista EXATA por documento confirma-se
 * com a Matilde/contabilista na Fase 3; se faltar algo é aditivo depois.
 *
 * Aditiva e reversível. Entidade nova — não toca em dados existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('name');                 // único obrigatório
            $table->string('nif')->nullable();
            $table->string('phone')->nullable();    // PII de contacto
            $table->string('email')->nullable();    // PII de contacto

            // Morada — mesmo padrão de companies/suppliers (inline + FKs lookup).
            $table->string('address')->nullable();
            $table->string('postal_code')->nullable();
            $table->foreignId('district_id')->nullable()->constrained();
            $table->foreignId('municipality_id')->nullable()->constrained();
            $table->foreignId('parish_id')->nullable()->constrained();

            // Dados legais (documentos de venda).
            $table->string('citizen_card_number')->nullable();
            $table->date('citizen_card_validity')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('nationality')->nullable();
            $table->string('marital_status', 50)->nullable(); // extensível

            // RGPD — consentimento centralizado no Cliente.
            $table->boolean('contact_consent')->default(false);

            $table->text('notes')->nullable();
            $table->boolean('archived')->default(false);

            $table->timestamps();

            $table->index(['company_id', 'name']);
            $table->index(['company_id', 'archived']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
