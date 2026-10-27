<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-mail 7 · De onde veio cada fatura carregada: "upload" (o carregamento manual) ou "email" (um anexo
 * importado da caixa de correio), com a caixa e o Message-ID da mensagem, sem o conteúdo do e-mail.
 * A coluna "source" já existe, mas diz como a fatura foi lida (QR, texto, imagem) e a leitura reescreve-a.
 *
 * ADITIVA: três colunas novas, nullable (as faturas antigas ficam sem origem).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ocr_invoices', 'origin')) {
            Schema::table('ocr_invoices', function (Blueprint $table) {
                $table->string('origin', 20)->nullable()->after('file_sha256');
                $table->unsignedBigInteger('origin_mailbox_id')->nullable()->after('origin');
                $table->string('origin_message_id', 500)->nullable()->after('origin_mailbox_id');
                $table->index('origin_mailbox_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ocr_invoices', 'origin')) {
            Schema::table('ocr_invoices', function (Blueprint $table) {
                $table->dropIndex(['origin_mailbox_id']);
                $table->dropColumn(['origin', 'origin_mailbox_id', 'origin_message_id']);
            });
        }
    }
};
