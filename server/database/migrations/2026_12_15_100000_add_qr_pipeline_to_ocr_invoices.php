<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — OCR faturas (F2a): QR da AT primeiro + linhas pela IA + conferência pelo QR.
 * Só colunas NOVAS e nulas: as faturas existentes ficam exatamente como estão.
 *   qr_*            QR lido (texto cru + campos) — fonte do cabeçalho quando existe
 *   buyer_nif/atcud/doc_type   do QR (B, H, D)
 *   source          qr+texto | qr+imagem | sem_qr ; lines_source texto|imagem
 *   pages/tokens/cost_usd/duration_ms/attempts/attempts_log   registo de custo por fatura
 *   check_status    confere | nao_confere | sem_qr ; check_diff = diferenças por taxa (cêntimos)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ocr_invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('ocr_invoices', 'qr_raw')) {
                $table->text('qr_raw')->nullable()->after('prompt_version');
                $table->boolean('qr_ok')->nullable()->after('qr_raw');
                $table->json('qr_data')->nullable()->after('qr_ok');
                $table->string('buyer_nif', 20)->nullable()->after('supplier_nif');
                $table->string('atcud', 100)->nullable()->after('number');
                $table->string('doc_type', 4)->nullable()->after('atcud');
                $table->string('source', 20)->nullable()->after('qr_data');
                $table->string('lines_source', 10)->nullable()->after('source');
                $table->unsignedSmallInteger('pages')->nullable()->after('lines_source');
                $table->unsignedInteger('tokens_in')->nullable()->after('pages');
                $table->unsignedInteger('tokens_out')->nullable()->after('tokens_in');
                $table->decimal('cost_usd', 10, 6)->nullable()->after('tokens_out');
                $table->unsignedInteger('duration_ms')->nullable()->after('cost_usd');
                $table->unsignedTinyInteger('attempts')->nullable()->after('duration_ms');
                $table->json('attempts_log')->nullable()->after('attempts');
                $table->string('check_status', 20)->nullable()->after('attempts_log');
                $table->json('check_diff')->nullable()->after('check_status');
            }
        });

        Schema::table('ocr_invoice_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('ocr_invoice_lines', 'supplier_code')) {
                $table->string('supplier_code', 60)->nullable()->after('position');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ocr_invoice_lines', function (Blueprint $table) {
            if (Schema::hasColumn('ocr_invoice_lines', 'supplier_code')) {
                $table->dropColumn('supplier_code');
            }
        });
        Schema::table('ocr_invoices', function (Blueprint $table) {
            if (Schema::hasColumn('ocr_invoices', 'qr_raw')) {
                $table->dropColumn([
                    'qr_raw', 'qr_ok', 'qr_data', 'buyer_nif', 'atcud', 'doc_type', 'source', 'lines_source',
                    'pages', 'tokens_in', 'tokens_out', 'cost_usd', 'duration_ms', 'attempts', 'attempts_log',
                    'check_status', 'check_diff',
                ]);
            }
        });
    }
};
