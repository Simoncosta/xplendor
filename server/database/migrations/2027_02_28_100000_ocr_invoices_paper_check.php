<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faturas sem QR legível: "Conferi os totais com o papel" (quem marcou e quando). É uma das três
 * condições para se poderem lançar no PingWin (com o adquirente certo e o IVA lido a bater).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ocr_invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('paper_checked_by')->nullable()->after('check_accepted_at');
            $table->timestamp('paper_checked_at')->nullable()->after('paper_checked_by');
        });
    }

    public function down(): void
    {
        Schema::table('ocr_invoices', function (Blueprint $table) {
            $table->dropColumn(['paper_checked_by', 'paper_checked_at']);
        });
    }
};
