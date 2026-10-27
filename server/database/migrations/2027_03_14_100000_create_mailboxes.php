<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-mail 1 · A caixa de correio de cada pessoa (e, no E-mail 6, a da empresa: sem dono). A caixa
 * vive no servidor de e-mail; aqui ficam só os dados para lá chegar (servidores, portas, utilizador
 * e a palavra-passe cifrada) e o estado: o último teste, o último erro e, para o E-mail 3, o
 * último UID lido, o UIDVALIDITY e a contagem dos não lidos.
 *
 * ADITIVA: uma tabela nova.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mailboxes')) {
            return;
        }
        Schema::create('mailboxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete(); // sem dono: a caixa da empresa (E-mail 6)
            $table->string('account_type', 20); // gmail, cpanel, manual
            $table->string('email');
            $table->string('smtp_host');
            $table->unsignedSmallInteger('smtp_port');
            $table->string('smtp_encryption', 10); // ssl (465) ou starttls (587)
            $table->string('imap_host');
            $table->unsignedSmallInteger('imap_port');
            $table->string('imap_encryption', 10);
            $table->string('username');
            $table->text('password'); // cifrada (cast encrypted), nunca devolvida pela API
            $table->string('sent_folder')->nullable(); // descoberta pelo LIST (E-mail 3)
            $table->boolean('save_to_sent')->default(true); // desligado no smtp.gmail.com, que já guarda a cópia
            $table->string('status', 20)->default('por_testar'); // por_testar, ativa, erro
            $table->text('last_error')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->unsignedBigInteger('last_uid')->nullable();
            $table->unsignedBigInteger('uidvalidity')->nullable();
            $table->unsignedInteger('unread_count')->nullable();
            $table->timestamp('unread_checked_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailboxes');
    }
};
