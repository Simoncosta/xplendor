<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Artigos PingWin (Etapa 0 da escrita): acrescenta ao catálogo os
 * campos do `maindataset` necessários para o form de criar/editar e o `raw`
 * (registo EXATO do servidor, à imagem de pingwin_units.raw) que será a fonte de
 * verdade no futuro EDIT,SAVE.
 *
 * ⚠️ Este incremento é SÓ LEITURA — as colunas ficam preparadas mas NADA escreve
 * no PingWin ainda (a escrita é a Etapa 1b, com o HAR de gravar).
 *
 * Preços: reutilizam-se saleprice_cents/purchaseprice_cents já existentes (int,
 * cêntimos) — NÃO se criam colunas de preço novas.
 *
 * Colunas com `_id` = ids do PingWin (o `raw` guarda o registo inteiro). Onde já
 * existia uma coluna de LEITURA com rótulo (product_type, product_status,
 * taxgroup, printzone), acrescenta-se a variante `_id` para não colidir com o que
 * o sync de leitura já preenche. Aditiva/idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pingwin_catalog_items')) {
            return;
        }

        Schema::table('pingwin_catalog_items', function (Blueprint $table) {
            // Registo EXATO do servidor (fonte de verdade para o futuro EDIT,SAVE).
            if (! Schema::hasColumn('pingwin_catalog_items', 'raw')) {
                $table->json('raw')->nullable()->after('synced_at');
            }

            // maindataset — flags/uso.
            if (! Schema::hasColumn('pingwin_catalog_items', 'forproduction')) {
                // ⚠️ mutuamente exclusivo com forpurchase (regra ao nível da app).
                $table->boolean('forproduction')->default(false)->after('forpurchase');
            }

            // maindataset — unidades por defeito (ids do PingWin).
            foreach ([
                'base_unit_id', 'default_sale_unit_id', 'default_purchase_unit_id',
                'default_stock_unit_id', 'label_unit_id', 'volume_unit_id',
            ] as $col) {
                if (! Schema::hasColumn('pingwin_catalog_items', $col)) {
                    $table->string($col)->nullable();
                }
            }

            // maindataset — classificações (ids; os rótulos de leitura ficam nas
            // colunas antigas taxgroup/printzone/product_type/product_status).
            foreach (['taxgroup_id', 'stockconfig_id', 'printzone_id', 'product_type_id', 'status_id'] as $col) {
                if (! Schema::hasColumn('pingwin_catalog_items', $col)) {
                    $table->string($col)->nullable();
                }
            }

            // maindataset — diversos.
            if (! Schema::hasColumn('pingwin_catalog_items', 'setexpireday')) {
                $table->integer('setexpireday')->nullable();        // validade em dias
            }
            if (! Schema::hasColumn('pingwin_catalog_items', 'weight')) {
                $table->decimal('weight', 16, 5)->nullable();
            }
            if (! Schema::hasColumn('pingwin_catalog_items', 'default_supplier_id')) {
                $table->string('default_supplier_id')->nullable(); // id do fornecedor no PingWin
            }
            if (! Schema::hasColumn('pingwin_catalog_items', 'fixedsupplier')) {
                $table->boolean('fixedsupplier')->default(false);
            }
            if (! Schema::hasColumn('pingwin_catalog_items', 'obs')) {
                $table->text('obs')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pingwin_catalog_items')) {
            return;
        }

        Schema::table('pingwin_catalog_items', function (Blueprint $table) {
            foreach ([
                'raw', 'forproduction',
                'base_unit_id', 'default_sale_unit_id', 'default_purchase_unit_id',
                'default_stock_unit_id', 'label_unit_id', 'volume_unit_id',
                'taxgroup_id', 'stockconfig_id', 'printzone_id', 'product_type_id', 'status_id',
                'setexpireday', 'weight', 'default_supplier_id', 'fixedsupplier', 'obs',
            ] as $col) {
                if (Schema::hasColumn('pingwin_catalog_items', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
