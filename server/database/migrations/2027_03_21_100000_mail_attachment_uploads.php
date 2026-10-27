<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-mail 5b · Os anexos no envio. Cada ficheiro carregado (do computador ou dos Ficheiros) fica só
 * temporariamente, em privado, até ao envio: apaga-se depois de enviar, ou ao fim de 24 horas se a
 * mensagem nunca for enviada. Na mensagem guardada fica só o nome e o tamanho de cada anexo.
 *
 * ADITIVA: uma tabela nova e uma coluna nova (nullable) em mail_messages.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mail_attachment_uploads')) {
            Schema::create('mail_attachment_uploads', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('mailbox_id')->index();
                $table->unsignedBigInteger('mail_message_id')->nullable()->index();
                $table->string('name', 255);
                $table->string('mime', 150);
                $table->unsignedInteger('size');
                $table->string('disk', 30);
                $table->string('path', 500);
                $table->string('source', 10); // upload | files
                $table->unsignedBigInteger('source_file_id')->nullable();
                $table->timestamp('expires_at')->index();
                $table->timestamps();
            });
        }
        if (! Schema::hasColumn('mail_messages', 'attachments')) {
            Schema::table('mail_messages', function (Blueprint $table) {
                $table->json('attachments')->nullable()->after('body_html');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('mail_messages', 'attachments')) {
            Schema::table('mail_messages', fn (Blueprint $table) => $table->dropColumn('attachments'));
        }
        Schema::dropIfExists('mail_attachment_uploads');
    }
};
