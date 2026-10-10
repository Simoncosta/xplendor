<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — F2b: artigos nas linhas da fatura OCR.
 *
 * ocr_invoice_lines:
 *   · PRECISÃO: unit_price (decimal 6 casas) substitui unit_price_cents (arredondava a cêntimos;
 *     backfill a partir dele). O unit_price_cents FICA (sem uso) e só sai numa migração futura,
 *     depois de confirmado em produção. quantity passa a 6 casas.
 *   · LIGAÇÃO ao artigo do catálogo: article_id, link_state (ligada | sugerida | por_ligar),
 *     link_method (mapa | pingwin | descricao | sugestao | manual | criado), link_confidence,
 *     link_suggestions (até 3), quem/quando; criação de artigo em curso (article_write_id) e a
 *     escrita do código do fornecedor no artigo (supplier_code_*).
 * supplier_article_map: aprendizagem NIF + código do fornecedor → artigo (UNIQUE por empresa).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ocr_invoice_lines', function (Blueprint $table) {
            $table->decimal('unit_price', 18, 6)->nullable()->after('unit');
            $table->unsignedBigInteger('article_id')->nullable()->after('vat_rate');
            $table->string('link_state', 12)->nullable()->after('article_id');
            $table->string('link_method', 12)->nullable()->after('link_state');
            $table->decimal('link_confidence', 5, 2)->nullable()->after('link_method');
            $table->json('link_suggestions')->nullable()->after('link_confidence');
            $table->unsignedBigInteger('linked_by')->nullable()->after('link_suggestions');
            $table->timestamp('linked_at')->nullable()->after('linked_by');
            $table->unsignedBigInteger('article_write_id')->nullable()->after('linked_at');
            $table->string('supplier_code_status', 12)->nullable()->after('article_write_id');
            $table->string('supplier_code_error', 500)->nullable()->after('supplier_code_status');
            $table->unsignedBigInteger('supplier_code_write_id')->nullable()->after('supplier_code_error');
            $table->index(['company_id', 'article_id'], 'ocr_line_article_idx');
        });

        // Backfill da precisão: o que existe passa para o preço com 6 casas (a partir dos cêntimos).
        DB::table('ocr_invoice_lines')->whereNotNull('unit_price_cents')->update(['unit_price' => DB::raw('unit_price_cents / 100')]);

        Schema::table('ocr_invoice_lines', function (Blueprint $table) {
            $table->decimal('quantity', 18, 6)->nullable()->change();
        });

        Schema::create('supplier_article_map', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('supplier_nif', 20);                 // só dígitos
            $table->string('supplier_code_norm', 60);           // trim, sem espaços nem zeros à esquerda, maiúsculas
            $table->string('supplier_code', 60);                // como veio
            $table->foreignId('article_id')->constrained('pingwin_catalog_items')->cascadeOnDelete();
            $table->string('source', 24);                       // f4_bootstrap | pingwin_supplierprices | ocr_link | manual
            $table->unsignedInteger('times_seen')->default(1);
            $table->unsignedInteger('conflicts')->default(0);   // outros artigos vistos com o mesmo código
            $table->timestamp('last_seen_at')->nullable();
            $table->decimal('last_price', 18, 6)->nullable();
            $table->string('unit', 30)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'supplier_nif', 'supplier_code_norm'], 'sam_company_nif_code_uq');
            $table->index(['company_id', 'article_id'], 'sam_company_article_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_article_map');
        Schema::table('ocr_invoice_lines', function (Blueprint $table) {
            $table->dropIndex('ocr_line_article_idx');
            $table->dropColumn(['unit_price', 'article_id', 'link_state', 'link_method', 'link_confidence', 'link_suggestions',
                'linked_by', 'linked_at', 'article_write_id', 'supplier_code_status', 'supplier_code_error', 'supplier_code_write_id']);
            $table->decimal('quantity', 12, 3)->nullable()->change();
        });
    }
};
