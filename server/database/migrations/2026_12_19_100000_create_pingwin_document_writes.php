<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — FB-1: "Lançar no PingWin". Cada escrita de documento (lançar em rascunho, fechar,
 * anular) é um registo: quem, o quê, o payload enviado, o resultado e a confirmação por releitura.
 * Estados: pendente | ok | erro (nada foi gravado) | erro_confirmacao (o SAVE pode ter corrido —
 * rever no PingWin; NUNCA se repete sozinho).
 * ocr_invoice_lines.launch_unit_id: a unidade do artigo escolhida pela pessoa quando a do OCR não
 * corresponde a nenhuma. ocr_invoices.check_accepted_*: a pessoa aceitou lançar com as linhas a não
 * conferirem com o QR.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pingwin_document_writes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ocr_invoice_id')->nullable()->constrained('ocr_invoices')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 10);                 // launch | close | void
            $table->string('status', 20)->default('pendente');
            $table->string('docheader_id', 30)->nullable();
            $table->string('document', 60)->nullable();   // ex.: VFT BOVFT/1077
            $table->json('payload')->nullable();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'ocr_invoice_id'], 'pw_docw_invoice_idx');
            $table->index(['company_id', 'status'], 'pw_docw_status_idx');
        });

        Schema::table('ocr_invoice_lines', function (Blueprint $table) {
            $table->string('launch_unit_id', 30)->nullable()->after('supplier_code_write_id');
        });

        Schema::table('ocr_invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('check_accepted_by')->nullable()->after('check_diff');
            $table->timestamp('check_accepted_at')->nullable()->after('check_accepted_by');
        });
    }

    public function down(): void
    {
        Schema::table('ocr_invoices', function (Blueprint $table) {
            $table->dropColumn(['check_accepted_by', 'check_accepted_at']);
        });
        Schema::table('ocr_invoice_lines', function (Blueprint $table) {
            $table->dropColumn('launch_unit_id');
        });
        Schema::dropIfExists('pingwin_document_writes');
    }
};
