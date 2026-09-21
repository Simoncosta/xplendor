<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — PingWin Fornecedores (Fase 1, só leitura): fornecedores do PingWin
 * por empresa. Peça-base (ligam-se a faturas/OCR e ao código do fornecedor nos
 * artigos — fases futuras). Aditiva/idempotente; nome de índice CURTO explícito
 * (<64 chars — lição da migração do CoverManager). NÃO confundir com a tabela
 * `suppliers` (fornecedores próprios do módulo finance) — domínio diferente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pingwin_suppliers')) {
            return;
        }

        Schema::create('pingwin_suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('pingwin_id');                 // id do fornecedor no PingWin (chave)
            $table->string('code')->nullable();
            $table->string('name')->nullable();
            $table->string('tax_number')->nullable();     // NIF/contribuinte
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true);  // = NOT deleted
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'pingwin_id'], 'pw_sup_company_pw_unique');
            $table->index(['company_id'], 'pw_sup_company_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pingwin_suppliers');
    }
};
