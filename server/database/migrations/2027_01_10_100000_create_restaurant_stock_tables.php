<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Fatia 7a: armazéns, stock (leitura) e consumo teórico.
 *  · pingwin_warehouses: completa o espelho (código, anulado, valorização, lojas servidas, loja principal).
 *  · pingwin_stock: o stock de cada artigo em cada armazém, tal como o PingWin o mostra (tbstock).
 *  · pingwin_stock_snapshots: uma fotografia por dia (quantidade e custos), para se ver a evolução.
 *  · pingwin_stock_items: o que a leitura do stock diz de cada artigo (se mexe stock, unidade de stock).
 *  · pingwin_stock_runs: as leituras (comando e agendamento), com tempos e falhas.
 *  · pingwin_stock_writes: as escritas no PingWin (mínimo/máximo e armazéns), uma de cada vez.
 *  · restaurant_store_warehouses: o armazém por omissão de cada loja, quando a XPLENDOR o define.
 * Aditiva e idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pingwin_warehouses')) {
            Schema::table('pingwin_warehouses', function (Blueprint $table) {
                if (! Schema::hasColumn('pingwin_warehouses', 'code')) {
                    $table->string('code', 20)->nullable()->after('pingwin_id');
                }
                if (! Schema::hasColumn('pingwin_warehouses', 'deleted')) {
                    $table->boolean('deleted')->default(false)->after('description');
                }
                if (! Schema::hasColumn('pingwin_warehouses', 'inv_costmethod')) {
                    $table->unsignedTinyInteger('inv_costmethod')->nullable()->after('deleted');
                }
                if (! Schema::hasColumn('pingwin_warehouses', 'exit_costmethod')) {
                    $table->unsignedTinyInteger('exit_costmethod')->nullable()->after('inv_costmethod');
                }
                if (! Schema::hasColumn('pingwin_warehouses', 'stores')) {
                    $table->json('stores')->nullable()->after('exit_costmethod');
                }
                if (! Schema::hasColumn('pingwin_warehouses', 'main_store_pingwin_id')) {
                    $table->string('main_store_pingwin_id', 32)->nullable()->after('stores');
                }
                if (! Schema::hasColumn('pingwin_warehouses', 'synced_at')) {
                    $table->timestamp('synced_at')->nullable()->after('main_store_pingwin_id');
                }
            });
        }

        if (! Schema::hasTable('pingwin_stock')) {
            Schema::create('pingwin_stock', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('product_pingwin_id', 32);
                $table->string('warehouse_pingwin_id', 32);
                $table->string('unit_name', 60)->nullable();
                $table->decimal('unit_value', 18, 6)->nullable();
                $table->decimal('qnt', 18, 5)->default(0);
                $table->decimal('pcm', 18, 6)->nullable();
                $table->decimal('pcu', 18, 6)->nullable();
                $table->date('valorization_date')->nullable();
                $table->decimal('min_stock', 18, 5)->nullable();
                $table->decimal('max_stock', 18, 5)->nullable();
                $table->boolean('disable_generic')->default(false);
                $table->decimal('qnt_pend_cli', 18, 5)->default(0);
                $table->decimal('qnt_pend_sup', 18, 5)->default(0);
                $table->timestamp('synced_at')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'product_pingwin_id', 'warehouse_pingwin_id'], 'pingwin_stock_unique');
                $table->index(['company_id', 'warehouse_pingwin_id']);
            });
        }

        if (! Schema::hasTable('pingwin_stock_snapshots')) {
            Schema::create('pingwin_stock_snapshots', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->date('snapshot_date');
                $table->string('product_pingwin_id', 32);
                $table->string('warehouse_pingwin_id', 32);
                $table->decimal('qnt', 18, 5)->default(0);
                $table->decimal('pcm', 18, 6)->nullable();
                $table->decimal('pcu', 18, 6)->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'snapshot_date', 'product_pingwin_id', 'warehouse_pingwin_id'], 'pingwin_stock_snapshots_unique');
                $table->index(['company_id', 'product_pingwin_id', 'snapshot_date'], 'pingwin_stock_snapshots_product');
            });
        }

        if (! Schema::hasTable('pingwin_stock_items')) {
            Schema::create('pingwin_stock_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('product_pingwin_id', 32);
                $table->string('stockconfig_id', 32)->nullable();
                $table->string('stockconfig', 60)->nullable();
                $table->boolean('movestock')->nullable();
                $table->string('stock_unit_id', 32)->nullable();
                $table->string('stock_unit', 60)->nullable();
                $table->timestamp('last_read_at')->nullable();
                $table->string('last_error', 300)->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'product_pingwin_id']);
            });
        }

        if (! Schema::hasTable('pingwin_stock_runs')) {
            Schema::create('pingwin_stock_runs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('origin', 20)->default('comando'); // comando | agendada | artigo
                $table->string('status', 20)->default('running'); // running | done | failed
                $table->unsignedInteger('products_total')->default(0);
                $table->unsignedInteger('products_read')->default(0);
                $table->unsignedInteger('products_failed')->default(0);
                $table->unsignedInteger('rows')->default(0);
                $table->decimal('seconds', 10, 1)->nullable();
                $table->text('error')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'status']);
            });
        }

        if (! Schema::hasTable('pingwin_stock_writes')) {
            Schema::create('pingwin_stock_writes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('kind', 30); // limites | armazem_criar | armazem_editar | armazem_apagar
                $table->string('product_pingwin_id', 32)->nullable();
                $table->string('warehouse_pingwin_id', 32)->nullable();
                $table->json('payload')->nullable();
                $table->json('expected')->nullable();
                $table->string('status', 20)->default('queued'); // queued | running | ok | mismatch | conflict | failed
                $table->json('result')->nullable();
                $table->text('error')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'status']);
                $table->index(['company_id', 'product_pingwin_id']);
            });
        }

        if (! Schema::hasTable('restaurant_store_warehouses')) {
            Schema::create('restaurant_store_warehouses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('store_pingwin_id', 32);
                $table->string('warehouse_pingwin_id', 32);
                $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['company_id', 'store_pingwin_id']);
            });
        }
    }

    public function down(): void
    {
        foreach (['restaurant_store_warehouses', 'pingwin_stock_writes', 'pingwin_stock_runs', 'pingwin_stock_items', 'pingwin_stock_snapshots', 'pingwin_stock'] as $t) {
            Schema::dropIfExists($t);
        }
        if (Schema::hasTable('pingwin_warehouses')) {
            Schema::table('pingwin_warehouses', function (Blueprint $table) {
                foreach (['code', 'deleted', 'inv_costmethod', 'exit_costmethod', 'stores', 'main_store_pingwin_id', 'synced_at'] as $c) {
                    if (Schema::hasColumn('pingwin_warehouses', $c)) {
                        $table->dropColumn($c);
                    }
                }
            });
        }
    }
};
