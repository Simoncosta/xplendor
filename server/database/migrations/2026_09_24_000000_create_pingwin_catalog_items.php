<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — PingWin Artigos (Fase 1, só leitura): catálogo de produtos por
 * empresa. São MUITOS → UPSERT idempotente por (company_id, pingwin_id).
 * Preços em CÊNTIMOS inteiros (nunca float). Aditiva/idempotente; nomes de índice
 * CURTOS explícitos (<64 chars — lição da migração do CoverManager).
 *
 * supplier_code fica NULLABLE (coluna preparada): o código do fornecedor é a
 * descobrir na fase do matching; o Storefire/browserdataset não o traz.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pingwin_catalog_items')) {
            return;
        }

        Schema::create('pingwin_catalog_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('pingwin_id');                       // id do artigo no PingWin (chave)
            $table->string('code')->nullable();
            $table->string('description')->nullable();
            $table->string('family')->nullable();
            $table->string('family_pingwin_id')->nullable();
            $table->boolean('forsale')->default(false);
            $table->boolean('forpurchase')->default(false);
            $table->boolean('has_bom')->default(false);         // tem ficha técnica (BOM)
            $table->string('product_type')->nullable();
            $table->string('product_status')->nullable();
            $table->string('taxgroup')->nullable();
            $table->string('printzone')->nullable();
            $table->integer('saleprice_cents')->nullable();     // CÊNTIMOS inteiros
            $table->integer('purchaseprice_cents')->nullable(); // CÊNTIMOS inteiros
            $table->string('saleunit')->nullable();
            $table->string('purchaseunit')->nullable();
            $table->string('order_code')->nullable();
            $table->string('supplier_code')->nullable();        // preparada; matching é fase futura
            $table->boolean('is_active')->default(true);        // = NOT deleted
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'pingwin_id'], 'pw_cat_item_company_pw_unique');
            $table->index(['company_id'], 'pw_cat_item_company_idx');
            $table->index(['company_id', 'family'], 'pw_cat_item_company_family_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pingwin_catalog_items');
    }
};
