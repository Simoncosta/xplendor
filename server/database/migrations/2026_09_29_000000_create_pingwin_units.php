<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — PingWin Unidades (Fase 1, só leitura): a base de conversão (compro em
 * Kg, converto em Un; Barril 50lt = 50 Litros). Guardadas FLAT com parent_pingwin_id
 * (unidade-base) + unit_value (fator). Há duplicados/apagados → is_active (= NOT
 * deleted). Aditiva/idempotente; nome de índice CURTO explícito (<64 chars).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pingwin_units')) {
            return;
        }

        Schema::create('pingwin_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('pingwin_id');                        // id da unidade no PingWin (chave)
            $table->string('description')->nullable();
            $table->string('shortname')->nullable();
            $table->string('product_pingwin_id')->nullable();    // ''/null = unidade global; preenchido = específica de artigo
            $table->string('parent_pingwin_id')->nullable();     // unidade-base para conversão
            $table->decimal('unit_value', 16, 5)->nullable();    // fator de conversão (ex.: 50)
            $table->boolean('purchase')->default(false);         // usável em compra
            $table->boolean('sale')->default(false);             // usável em venda
            $table->boolean('stock')->default(false);            // usável em stock
            $table->decimal('net_weight', 16, 5)->nullable();
            $table->string('external_measure')->nullable();
            $table->boolean('is_active')->default(true);         // = NOT deleted
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'pingwin_id'], 'pw_unit_company_pw_unique');
            $table->index(['company_id', 'is_active'], 'pw_unit_company_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pingwin_units');
    }
};
