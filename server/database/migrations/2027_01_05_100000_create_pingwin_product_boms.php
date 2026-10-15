<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Fatia 1: espelho das fichas técnicas do PingWin (SÓ LEITURA).
 *  · pingwin_product_boms: as versões da ficha de cada artigo (data de início = cashbox_date);
 *  · pingwin_product_bom_stores: as lojas de cada versão (ativa = deleted 0 no PingWin);
 *  · pingwin_product_bom_lines: as linhas (componentes) de cada versão;
 *  · pingwin_product_bom_line_costs: o custo de cada linha por armazém ("" = todos);
 *  · pingwin_warehouses: os armazéns (nome para o ecrã).
 * E, no artigo e nas unidades, o que a ficha precisa e ainda não estava no espelho.
 * Aditiva e idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pingwin_product_boms')) {
            Schema::create('pingwin_product_boms', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('product_pingwin_id', 32);
                $table->string('bom_pingwin_id', 32);
                $table->string('version_key', 16)->nullable();
                $table->date('valid_from')->nullable();
                $table->boolean('deleted')->default(false);
                $table->timestamp('synced_at')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'bom_pingwin_id']);
                $table->index(['company_id', 'product_pingwin_id']);
            });
        }

        if (! Schema::hasTable('pingwin_product_bom_stores')) {
            Schema::create('pingwin_product_bom_stores', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('bom_pingwin_id', 32);
                $table->string('store_pingwin_id', 32);
                $table->string('store_code', 32)->nullable();
                $table->string('store_name')->nullable();
                $table->boolean('active')->default(true);
                $table->timestamps();

                $table->unique(['company_id', 'bom_pingwin_id', 'store_pingwin_id'], 'pw_bom_stores_unique');
            });
        }

        if (! Schema::hasTable('pingwin_product_bom_lines')) {
            Schema::create('pingwin_product_bom_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('bom_pingwin_id', 32);
                $table->string('line_key', 40);
                $table->string('component_pingwin_id', 32)->nullable();
                $table->string('component_code', 64)->nullable();
                $table->string('component_description')->nullable();
                $table->string('unit_pingwin_id', 32)->nullable();
                $table->string('unit_desc')->nullable();
                $table->decimal('qnt', 18, 6)->nullable();
                $table->decimal('qnt_base', 18, 6)->nullable();
                $table->decimal('waste_pct', 9, 4)->default(0);
                $table->decimal('recovery_pct', 9, 4)->default(0);
                $table->boolean('is_bom')->default(false);
                $table->smallInteger('component_status')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'bom_pingwin_id', 'line_key'], 'pw_bom_lines_unique');
                $table->index(['company_id', 'component_pingwin_id']);
            });
        }

        if (! Schema::hasTable('pingwin_product_bom_line_costs')) {
            Schema::create('pingwin_product_bom_line_costs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('bom_line_id')->constrained('pingwin_product_bom_lines')->cascadeOnDelete();
                $table->string('warehouse_pingwin_id', 32)->default(''); // "" = todos os armazéns
                $table->decimal('unit_cost', 18, 6)->nullable();
                $table->decimal('cost', 18, 6)->nullable();
                $table->timestamps();

                $table->unique(['bom_line_id', 'warehouse_pingwin_id'], 'pw_bom_line_costs_unique');
            });
        }

        if (! Schema::hasTable('pingwin_warehouses')) {
            Schema::create('pingwin_warehouses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('pingwin_id', 32);
                $table->string('description')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'pingwin_id']);
            });
        }

        // O artigo: o armazém do custo e o preço sem IVA da ficha (as unidades por função, forsale,
        // forpurchase, forproduction e stockconfig_id já existiam no espelho do catálogo).
        Schema::table('pingwin_catalog_items', function (Blueprint $table) {
            if (! Schema::hasColumn('pingwin_catalog_items', 'cost_warehouse_id')) {
                $table->string('cost_warehouse_id', 32)->nullable();
            }
            if (! Schema::hasColumn('pingwin_catalog_items', 'saleprice_wo_tax_cents')) {
                $table->integer('saleprice_wo_tax_cents')->nullable();
            }
            if (! Schema::hasColumn('pingwin_catalog_items', 'bom_synced_at')) {
                $table->timestamp('bom_synced_at')->nullable();
            }
        });

        // As unidades: o pai já existia (parent_pingwin_id); falta a quantidade do pai.
        if (Schema::hasTable('pingwin_units') && ! Schema::hasColumn('pingwin_units', 'parent_qnt')) {
            Schema::table('pingwin_units', function (Blueprint $table) {
                $table->decimal('parent_qnt', 18, 6)->nullable()->after('parent_pingwin_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pingwin_product_bom_line_costs');
        Schema::dropIfExists('pingwin_product_bom_lines');
        Schema::dropIfExists('pingwin_product_bom_stores');
        Schema::dropIfExists('pingwin_product_boms');
        Schema::dropIfExists('pingwin_warehouses');
        Schema::table('pingwin_catalog_items', function (Blueprint $table) {
            foreach (['cost_warehouse_id', 'saleprice_wo_tax_cents', 'bom_synced_at'] as $col) {
                if (Schema::hasColumn('pingwin_catalog_items', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        if (Schema::hasTable('pingwin_units') && Schema::hasColumn('pingwin_units', 'parent_qnt')) {
            Schema::table('pingwin_units', function (Blueprint $table) {
                $table->dropColumn('parent_qnt');
            });
        }
    }
};
