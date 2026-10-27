<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-mail 5 · Escrever e responder: a assinatura opcional de cada caixa (texto simples, acrescentada no
 * fim das mensagens novas e das respostas) e o Cc das mensagens enviadas.
 *
 * ADITIVA: duas colunas novas, nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('mailboxes', 'signature')) {
            Schema::table('mailboxes', function (Blueprint $table) {
                $table->text('signature')->nullable()->after('mark_seen_on_server');
            });
        }
        if (! Schema::hasColumn('mail_messages', 'cc_address')) {
            Schema::table('mail_messages', function (Blueprint $table) {
                $table->text('cc_address')->nullable()->after('to_address');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('mail_messages', 'cc_address')) {
            Schema::table('mail_messages', fn (Blueprint $table) => $table->dropColumn('cc_address'));
        }
        if (Schema::hasColumn('mailboxes', 'signature')) {
            Schema::table('mailboxes', fn (Blueprint $table) => $table->dropColumn('signature'));
        }
    }
};
