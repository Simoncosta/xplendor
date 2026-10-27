<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-mail 7 · O endereço da caixa de onde veio a fatura (o selo "Veio do e-mail" na página da fatura).
 * Guarda-se no momento da importação: continua a mostrar-se mesmo que a caixa seja removida.
 *
 * ADITIVA: uma coluna nova, nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ocr_invoices', 'origin_mailbox_email')) {
            Schema::table('ocr_invoices', function (Blueprint $table) {
                $table->string('origin_mailbox_email', 255)->nullable()->after('origin_mailbox_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ocr_invoices', 'origin_mailbox_email')) {
            Schema::table('ocr_invoices', fn (Blueprint $table) => $table->dropColumn('origin_mailbox_email'));
        }
    }
};
