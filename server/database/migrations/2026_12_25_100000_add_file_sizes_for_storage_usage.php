<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Espaço por empresa (complemento ao pré-deploy, ponto 4): o tamanho de cada ficheiro guardado
 * fica na base de dados, para a soma por empresa não ter de listar o disco nem o bucket.
 * Só colunas novas (nulas até o ficheiro ser gravado, ou o comando storage:fill-sizes as preencher).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_assets', function (Blueprint $table) {
            $table->unsignedBigInteger('variants_bytes')->nullable()->after('size_bytes'); // miniatura, pré-visualização e poster
        });
        Schema::table('ocr_invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('image_size_bytes')->nullable()->after('image_path');
        });
        Schema::table('expense_charges', function (Blueprint $table) {
            $table->unsignedBigInteger('invoice_size_bytes')->nullable()->after('invoice_path');
            $table->unsignedBigInteger('proof_size_bytes')->nullable()->after('proof_path');
        });
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->unsignedBigInteger('invoice_size_bytes')->nullable()->after('invoice_path');
        });
        Schema::table('satisfaction_report_photos', function (Blueprint $table) {
            $table->unsignedBigInteger('size_bytes')->nullable()->after('path');
        });
    }

    public function down(): void
    {
        Schema::table('media_assets', fn (Blueprint $t) => $t->dropColumn('variants_bytes'));
        Schema::table('ocr_invoices', fn (Blueprint $t) => $t->dropColumn('image_size_bytes'));
        Schema::table('expense_charges', fn (Blueprint $t) => $t->dropColumn(['invoice_size_bytes', 'proof_size_bytes']));
        Schema::table('support_tickets', fn (Blueprint $t) => $t->dropColumn('invoice_size_bytes'));
        Schema::table('satisfaction_report_photos', fn (Blueprint $t) => $t->dropColumn('size_bytes'));
    }
};
