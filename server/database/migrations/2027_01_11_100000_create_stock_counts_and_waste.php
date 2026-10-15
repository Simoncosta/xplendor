<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Fatia 7b: contagem de stock, desperdício e movimentos.
 *  · pingwin_countable_products: os artigos que a Contagem (1106) do PingWin aceita.
 *  · stock_count_zones / stock_count_zone_items: as zonas de contagem de cada armazém e a ordem.
 *  · stock_counts / stock_count_lines: as contagens (contar, rever, aprovar e enviar) e as linhas.
 *  · waste_batches / waste_entries: o desperdício registado e os documentos enviados ao PingWin.
 * As escritas no PingWin ficam em pingwin_stock_writes (Fatia 7a), com os tipos novos.
 * Aditiva e idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pingwin_countable_products')) {
            Schema::create('pingwin_countable_products', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('product_id', 32);
                $table->string('code', 40)->nullable();
                $table->string('description')->nullable();
                $table->string('family')->nullable();
                $table->timestamp('refreshed_at')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'product_id']);
            });
        }

        if (! Schema::hasTable('stock_count_zones')) {
            Schema::create('stock_count_zones', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('warehouse_id', 32);
                $table->string('name', 80);
                $table->unsignedInteger('position')->default(0);
                $table->timestamps();

                $table->index(['company_id', 'warehouse_id']);
            });
        }

        if (! Schema::hasTable('stock_count_zone_items')) {
            Schema::create('stock_count_zone_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('zone_id')->constrained('stock_count_zones')->cascadeOnDelete();
                $table->string('product_id', 32);
                $table->unsignedInteger('position')->default(0);
                $table->timestamps();

                $table->unique(['zone_id', 'product_id']);
            });
        }

        if (! Schema::hasTable('stock_counts')) {
            Schema::create('stock_counts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('warehouse_id', 32);
                $table->string('scope', 20)->default('completa');           // completa | artigos_a | zona
                $table->foreignId('zone_id')->nullable()->constrained('stock_count_zones')->nullOnDelete();
                $table->string('moment', 20)->default('antes_abertura');    // antes_abertura | depois_fecho
                $table->date('inventory_date');
                $table->boolean('blind')->default(true);
                $table->string('status', 20)->default('em_contagem');       // em_contagem | em_revisao | a_enviar | enviada | falhou | anulada
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->string('pingwin_doc_id', 32)->nullable();
                $table->string('pingwin_doc_number', 60)->nullable();
                $table->text('error_message')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamp('voided_at')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'warehouse_id', 'status']);
            });
        }

        if (! Schema::hasTable('stock_count_lines')) {
            Schema::create('stock_count_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('count_id')->constrained('stock_counts')->cascadeOnDelete();
                $table->string('product_id', 32);
                $table->string('code', 40)->nullable();
                $table->string('description')->nullable();
                $table->char('abc_class', 1)->nullable();
                $table->foreignId('zone_id')->nullable()->constrained('stock_count_zones')->nullOnDelete();
                $table->unsignedInteger('position')->default(0);
                $table->string('stock_unit', 60)->nullable();
                $table->string('stock_unit_id', 32)->nullable();
                $table->json('entries')->nullable();
                $table->decimal('qty_base', 18, 5)->nullable();                 // null = não contado
                $table->foreignId('counted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('counted_at')->nullable();
                $table->boolean('recount_requested')->default(false);
                $table->decimal('previous_qty_base', 18, 5)->nullable();
                $table->decimal('theoretical_snapshot', 18, 5)->nullable();
                $table->timestamp('snapshot_at')->nullable();
                $table->decimal('unit_cost_snapshot', 18, 6)->nullable();
                $table->string('pingwin_unit_id', 32)->nullable();
                $table->decimal('pingwin_theoretical_qnt', 18, 5)->nullable();
                $table->decimal('pingwin_unit_cost', 18, 6)->nullable();
                $table->decimal('pingwin_qnt_difference', 18, 5)->nullable();
                $table->decimal('pingwin_price_difference', 18, 4)->nullable();
                $table->decimal('confirmed_qty', 18, 5)->nullable();
                $table->string('confirm_status', 20)->nullable();               // confirmado | diferente
                $table->timestamps();

                $table->unique(['count_id', 'product_id']);
            });
        }

        if (! Schema::hasTable('waste_batches')) {
            Schema::create('waste_batches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('warehouse_id', 32);
                $table->date('date');
                $table->string('status', 20)->default('a_enviar');          // a_enviar | enviada | falhou | anulada
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->string('pingwin_doc_id', 32)->nullable();
                $table->string('pingwin_doc_number', 60)->nullable();
                $table->json('lines')->nullable();                             // as linhas enviadas (já explodidas)
                $table->decimal('pingwin_total', 14, 2)->nullable();
                $table->text('error_message')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'warehouse_id', 'status']);
            });
        }

        if (! Schema::hasTable('waste_entries')) {
            Schema::create('waste_entries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('warehouse_id', 32);
                $table->string('product_id', 32);
                $table->string('code', 40)->nullable();
                $table->string('description')->nullable();
                $table->string('source_product_id', 32)->nullable();          // o prato com ficha (explode ao enviar)
                $table->decimal('qty', 18, 5);
                $table->string('unit', 60)->nullable();
                $table->decimal('factor', 18, 6)->default(1);
                $table->decimal('qty_base', 18, 5);
                $table->string('reason', 30);
                $table->string('note', 255)->nullable();
                $table->decimal('value_estimate', 14, 4)->nullable();
                $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('registered_at')->nullable();
                $table->foreignId('batch_id')->nullable()->constrained('waste_batches')->nullOnDelete();
                $table->timestamps();

                $table->index(['company_id', 'warehouse_id', 'registered_at']);
            });
        }
    }

    public function down(): void
    {
        foreach (['waste_entries', 'waste_batches', 'stock_count_lines', 'stock_counts', 'stock_count_zone_items', 'stock_count_zones', 'pingwin_countable_products'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
