<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-mail 2 · As mensagens que a XPLENDOR guarda: as que envia pelas caixas de correio (a começar
 * pelo link de pós-venda) e, no E-mail 4, as respostas que se ligam a um cliente. A caixa vive no
 * servidor de e-mail; aqui fica só o que se liga a um cliente, a uma venda, a uma lead ou a um
 * relatório, com o Message-ID para encontrar as respostas no mesmo fio.
 *
 * ADITIVA: uma tabela nova.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mail_messages')) {
            return;
        }
        Schema::create('mail_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mailbox_id')->nullable()->constrained()->nullOnDelete(); // a caixa pode ser removida; a mensagem ligada fica
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // quem enviou
            $table->string('direction', 3); // out (enviada) ou in (recebida, E-mail 4)
            $table->string('message_id', 500)->nullable()->index();
            $table->string('in_reply_to', 500)->nullable();
            $table->text('references')->nullable();
            $table->string('from_address');
            $table->string('to_address');
            $table->string('subject', 500);
            $table->longText('body_text')->nullable();
            $table->longText('body_html')->nullable(); // limpo
            $table->string('status', 20); // a_enviar, enviada, erro
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('car_sale_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('car_lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('satisfaction_report_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['mailbox_id', 'direction', 'created_at']); // os limites por caixa
            $table->index(['company_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_messages');
    }
};
