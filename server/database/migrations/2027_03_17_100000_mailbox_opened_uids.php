<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-mail 4 · Ler numa caixa Gmail sem a marcar como lida no Gmail: a opção da caixa
 * (mark_seen_on_server, ligada por omissão) e as mensagens que a pessoa já abriu na XPLENDOR
 * (só a pasta, o UIDVALIDITY e o UID; nenhum conteúdo), para a lista e o número de não lidos.
 *
 * ADITIVA: uma coluna nova (com valor por omissão) e uma tabela nova.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('mailboxes', 'mark_seen_on_server')) {
            Schema::table('mailboxes', function (Blueprint $table) {
                $table->boolean('mark_seen_on_server')->default(true)->after('save_to_sent');
            });
        }
        if (! Schema::hasTable('mailbox_opened_uids')) {
            Schema::create('mailbox_opened_uids', function (Blueprint $table) {
                $table->id();
                $table->foreignId('mailbox_id')->constrained()->cascadeOnDelete();
                $table->string('folder');
                $table->unsignedBigInteger('uidvalidity');
                $table->unsignedBigInteger('uid');
                $table->timestamp('created_at')->nullable();

                $table->unique(['mailbox_id', 'folder', 'uidvalidity', 'uid'], 'mailbox_opened_uids_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_opened_uids');
        if (Schema::hasColumn('mailboxes', 'mark_seen_on_server')) {
            Schema::table('mailboxes', function (Blueprint $table) {
                $table->dropColumn('mark_seen_on_server');
            });
        }
    }
};
