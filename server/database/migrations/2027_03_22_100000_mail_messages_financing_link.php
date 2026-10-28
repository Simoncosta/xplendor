<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-mail 9 · O link de financiamento e o de retoma enviados pela caixa: a mensagem fica ligada ao link
 * (financing_link_id). A cópia guardada leva o link sem o token; o texto completo (com o link) fica
 * cifrado em outgoing_secret só até o envio, e apaga-se logo a seguir (bem ou mal).
 *
 * ADITIVA: duas colunas novas, nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('mail_messages', 'financing_link_id')) {
            Schema::table('mail_messages', function (Blueprint $table) {
                $table->unsignedBigInteger('financing_link_id')->nullable()->after('car_lead_id');
                $table->index('financing_link_id');
            });
        }
        if (! Schema::hasColumn('mail_messages', 'outgoing_secret')) {
            Schema::table('mail_messages', function (Blueprint $table) {
                $table->longText('outgoing_secret')->nullable()->after('body_html');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('mail_messages', 'outgoing_secret')) {
            Schema::table('mail_messages', fn (Blueprint $table) => $table->dropColumn('outgoing_secret'));
        }
        if (Schema::hasColumn('mail_messages', 'financing_link_id')) {
            Schema::table('mail_messages', function (Blueprint $table) {
                $table->dropIndex(['financing_link_id']);
                $table->dropColumn('financing_link_id');
            });
        }
    }
};
