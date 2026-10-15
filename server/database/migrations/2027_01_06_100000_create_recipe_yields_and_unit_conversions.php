<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Fatia 1b (só na XPLENDOR; nada vai para a PingWin):
 *  · product_bom_yields: o rendimento de uma versão da ficha de um semi-produto (ex.: o lote do
 *    Molho rende 30 L). A PingWin não tem este campo. Só se grava quando alguém confirma; a
 *    sugestão calcula-se ao ler.
 *  · product_unit_conversions: quantas unidades base vale uma unidade de compra de um artigo
 *    (ex.: AVINHEZ BRANCO, "Unidade" = 20 L), opcionalmente só para um fornecedor (NIF). O custo
 *    XPLENDOR usa o fator confirmado antes da qnt_base do raw da linha de compra.
 * Aditiva e idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_bom_yields')) {
            Schema::create('product_bom_yields', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('product_pingwin_id', 32);
                $table->string('bom_pingwin_id', 32);
                $table->decimal('quantity', 18, 6);
                $table->string('unit_pingwin_id', 32)->nullable();
                $table->string('unit_label', 60)->nullable();
                $table->string('source', 16)->default('confirmado'); // sugerido | confirmado
                $table->string('reason')->nullable();                // de onde veio a sugestão aceite
                $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'bom_pingwin_id']);
                $table->index(['company_id', 'product_pingwin_id']);
            });
        }

        if (! Schema::hasTable('product_unit_conversions')) {
            Schema::create('product_unit_conversions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('product_pingwin_id', 32);
                $table->string('unit_key', 64);                      // id da unidade na PingWin, ou o rótulo
                $table->string('unit_label', 60)->nullable();
                $table->string('supplier_nif', 20)->default('');     // '' = todos os fornecedores
                $table->decimal('factor_to_base', 18, 6);
                $table->string('source', 16)->default('confirmado'); // sugerido | confirmado
                $table->string('reason')->nullable();
                $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'product_pingwin_id', 'unit_key', 'supplier_nif'], 'product_unit_conv_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_unit_conversions');
        Schema::dropIfExists('product_bom_yields');
    }
};
