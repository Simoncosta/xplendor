<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-mail 5 · Os endereços de teste de cada caixa (só em dev): as mensagens para esses endereços saem
 * mesmo para eles; tudo o resto continua a ir só para a própria caixa. Em produção não têm efeito.
 *
 * ADITIVA: uma coluna nova, nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('mailboxes', 'dev_test_addresses')) {
            Schema::table('mailboxes', function (Blueprint $table) {
                $table->text('dev_test_addresses')->nullable()->after('signature');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('mailboxes', 'dev_test_addresses')) {
            Schema::table('mailboxes', fn (Blueprint $table) => $table->dropColumn('dev_test_addresses'));
        }
    }
};
