<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — F3: ligação Fatura OCR ↔ Documento(s) do PingWin (só espelhos; nunca escreve no PingWin).
 *
 * ocr_invoice_pingwin_links: N documentos por fatura (fatura de guias). method: numero |
 *   total_data | guias | manual. confirmed_* = a pessoa confirmou (total_data fica por confirmar).
 * ocr_invoices: link_status (fornecedor_em_falta | nao_lancada | possivel | lancada |
 *   lancada_guias | duplicada), diferença, última verificação, aviso (ex.: documento anulado),
 *   candidatos (ids), documentos rejeitados ("Desligar"), duplicada de, guias lidas da fatura.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ocr_invoice_pingwin_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ocr_invoice_id')->constrained('ocr_invoices')->cascadeOnDelete();
            $table->string('docheader_id', 30);
            $table->string('method', 12);                         // numero | total_data | guias | manual
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->unique(['ocr_invoice_id', 'docheader_id'], 'ocr_pw_link_uq');
            $table->index(['company_id', 'docheader_id'], 'ocr_pw_link_doc_idx');
        });

        Schema::table('ocr_invoices', function (Blueprint $table) {
            $table->string('link_status', 24)->nullable()->after('check_diff');
            $table->bigInteger('link_diff_cents')->nullable()->after('link_status');
            $table->timestamp('link_checked_at')->nullable()->after('link_diff_cents');
            $table->string('link_note', 255)->nullable()->after('link_checked_at');
            $table->json('link_candidates')->nullable()->after('link_note');
            $table->json('link_rejected')->nullable()->after('link_candidates');
            $table->boolean('link_search_pending')->default(false)->after('link_rejected');
            $table->unsignedBigInteger('duplicate_of_id')->nullable()->after('link_search_pending');
            $table->json('guide_refs')->nullable()->after('duplicate_of_id');

            $table->index(['company_id', 'link_status'], 'ocr_inv_link_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('ocr_invoices', function (Blueprint $table) {
            $table->dropIndex('ocr_inv_link_status_idx');
            $table->dropColumn(['link_status', 'link_diff_cents', 'link_checked_at', 'link_note', 'link_candidates',
                'link_rejected', 'link_search_pending', 'duplicate_of_id', 'guide_refs']);
        });
        Schema::dropIfExists('ocr_invoice_pingwin_links');
    }
};
