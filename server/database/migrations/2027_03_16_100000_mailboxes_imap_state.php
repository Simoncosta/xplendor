<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-mail 3 · O estado do IMAP de cada caixa, à parte do envio (SMTP): o último "Testar IMAP" ou a
 * última contagem dos não lidos, e o motivo quando falha.
 *
 * ADITIVA: três colunas novas, nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            if (! Schema::hasColumn('mailboxes', 'imap_status')) {
                $table->string('imap_status', 20)->nullable()->after('last_tested_at'); // null (por testar), ativa, erro
            }
            if (! Schema::hasColumn('mailboxes', 'imap_error')) {
                $table->text('imap_error')->nullable()->after('imap_status');
            }
            if (! Schema::hasColumn('mailboxes', 'imap_tested_at')) {
                $table->timestamp('imap_tested_at')->nullable()->after('imap_error');
            }
        });
    }

    public function down(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            foreach (['imap_tested_at', 'imap_error', 'imap_status'] as $column) {
                if (Schema::hasColumn('mailboxes', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
