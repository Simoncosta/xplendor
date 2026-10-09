<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Linhas dos documentos de fornecedor do PingWin (F4, SÓ LEITURA).
 *
 * pingwin_supplier_document_lines: uma linha por (company_id, docheader_id, line_number).
 *   Preços com PRECISÃO TOTAL (decimal 6 casas — há preços com 3+ casas); totais em cêntimos.
 *   As linhas de um documento são substituídas em bloco (transação) só quando a leitura correu bem.
 *
 * pingwin_supplier_documents (+ colunas do header lido no documento e estado da leitura):
 *   (desconto de linhas e acerto: migração seguinte, 2026_12_16_100001)
 *   lines_synced_total_cents / lines_synced_docstatus_id = total e estado da LISTA no momento
 *   da leitura → a sync incremental relê o documento se algum deles mudar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pingwin_supplier_document_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('docheader_id', 30);
            $table->unsignedInteger('line_number');
            $table->string('line_pingwin_id', 30)->nullable();
            $table->string('product_pingwin_id', 30)->nullable();
            $table->string('product_code', 60)->nullable();
            $table->foreignId('article_id')->nullable()->constrained('pingwin_catalog_items')->nullOnDelete();
            $table->string('supplier_code', 60)->nullable();          // entity_product_id ("Código do fornecedor")
            $table->string('description')->nullable();
            $table->decimal('qnt', 18, 6)->nullable();
            $table->string('unit_code', 30)->nullable();
            $table->string('unit_desc', 60)->nullable();
            $table->decimal('price', 18, 6)->nullable();               // sem IVA, precisão total
            $table->decimal('price_w_tax', 18, 6)->nullable();
            $table->decimal('discount1', 9, 4)->nullable();            // %
            $table->bigInteger('total_cents')->default(0);             // sem IVA
            $table->bigInteger('tax_value_cents')->default(0);
            $table->bigInteger('total_w_tax_cents')->default(0);
            $table->string('taxgroup_id', 30)->nullable();
            $table->string('tax_description', 60)->nullable();
            $table->string('warehouse_pingwin_id', 30)->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'docheader_id', 'line_number'], 'pw_sdl_doc_line_uq');
            $table->index(['company_id', 'product_pingwin_id'], 'pw_sdl_product_idx');
            $table->index(['company_id', 'article_id'], 'pw_sdl_article_idx');
        });

        Schema::table('pingwin_supplier_documents', function (Blueprint $table) {
            $table->date('due_date')->nullable()->after('fiscal_date');
            $table->string('paycond_pingwin_id', 30)->nullable()->after('due_date');
            $table->date('docreference_date')->nullable()->after('docreference_number');
            $table->bigInteger('total_products_cents')->nullable()->after('total_cents');
            $table->bigInteger('total_tax_cents')->nullable()->after('total_products_cents');
            $table->decimal('discount1', 9, 4)->nullable()->after('total_tax_cents');
            $table->decimal('discount2', 9, 4)->nullable()->after('discount1');
            $table->bigInteger('shipping_cents')->nullable()->after('discount2');
            $table->bigInteger('withholding_cents')->nullable()->after('shipping_cents');
            $table->timestamp('lines_synced_at')->nullable();
            $table->string('lines_status', 10)->nullable();            // ok | failed
            $table->string('lines_error', 500)->nullable();
            $table->string('lines_check', 10)->nullable();             // ok | diff
            $table->bigInteger('lines_diff_cents')->nullable();        // Σ linhas − (total_products − detail_discount)
            $table->bigInteger('lines_tax_diff_cents')->nullable();    // Σ IVA linhas − total_tax
            $table->bigInteger('lines_synced_total_cents')->nullable();
            $table->string('lines_synced_docstatus_id', 30)->nullable();

            $table->index(['company_id', 'lines_status'], 'pw_sd_lines_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('pingwin_supplier_documents', function (Blueprint $table) {
            $table->dropIndex('pw_sd_lines_status_idx');
            $table->dropColumn([
                'due_date', 'paycond_pingwin_id', 'docreference_date', 'total_products_cents', 'total_tax_cents',
                'discount1', 'discount2', 'shipping_cents', 'withholding_cents', 'lines_synced_at', 'lines_status',
                'lines_error', 'lines_check', 'lines_diff_cents', 'lines_tax_diff_cents', 'lines_synced_total_cents',
                'lines_synced_docstatus_id',
            ]);
        });
        Schema::dropIfExists('pingwin_supplier_document_lines');
    }
};
