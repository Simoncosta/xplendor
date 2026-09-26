<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — PingWin › tab Compras (C1, SÓ LEITURA): espelho das linhas de fornecedor de
 * um artigo (dataset tbsupprice). Ligado aos suppliers UNIFICADOS (source=pingwin) e ao
 * pingwin_catalog_items (relink soft por id natural — sem FK dura, padrão dos mirrors).
 * Preços em CÊNTIMOS (int, nunca float); `raw` guarda a linha original (fonte de verdade);
 * is_active reflete deleted. Idempotente por (company_id, line_pingwin_id). NÃO escreve no
 * PingWin — atualiza-se só ao LER o artigo (como o catálogo). table_name/supprice_header_id
 * ficam INLINE (a "tabela de fornecedor" separada fica para quando fizer falta).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pingwin_supplier_prices')) {
            return;
        }

        Schema::create('pingwin_supplier_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // Relink soft (sem FK dura) — resolvidos pelos ids naturais do PingWin.
            $table->unsignedBigInteger('catalog_item_id')->nullable(); // → pingwin_catalog_items.id
            $table->string('product_pingwin_id');                      // product_id da linha (id do artigo)
            $table->unsignedBigInteger('supplier_id')->nullable();     // → suppliers.id (source=pingwin)
            $table->string('supplier_pingwin_id')->nullable();
            $table->string('supplier_name')->nullable();

            $table->string('line_pingwin_id');        // 'id' da linha tbsupprice (chave do upsert)
            $table->string('supprice_header_id')->nullable();
            $table->string('table_name')->nullable();

            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('currency')->nullable();
            $table->string('unit_id')->nullable();
            $table->string('unit_name')->nullable();

            $table->string('sup_product_description')->nullable();
            $table->string('sup_product_code')->nullable();
            $table->string('sup_product_barcode')->nullable();

            $table->integer('price_cents')->nullable();      // decimalToCents; nunca float
            $table->tinyInteger('currprecision')->nullable(); // guardado por fidelidade
            $table->decimal('discount1', 10, 4)->nullable();
            $table->decimal('discount2_mul', 10, 4)->nullable();

            $table->json('raw')->nullable();                 // linha crua = fonte de verdade
            $table->boolean('is_active')->default(true);     // deleted:1 → false
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            // Índice curto explícito (lição das migrações anteriores: <64 chars).
            $table->unique(['company_id', 'line_pingwin_id'], 'pw_supprice_company_line_unique');
            $table->index(['company_id', 'catalog_item_id'], 'pw_supprice_company_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pingwin_supplier_prices');
    }
};
