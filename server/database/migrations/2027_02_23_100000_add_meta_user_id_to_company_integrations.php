<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anúncios da Meta: o utilizador da Meta que autorizou cada ligação (vem do debug_token). Ao
 * desligar uma empresa, só se revoga a permissão na Meta se nenhuma outra empresa usar o mesmo
 * utilizador. As ligações antigas ficam sem valor (desconhecido) até voltarem a ser ligadas.
 *
 * ADITIVA: só uma coluna nova, nullable, com índice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('company_integrations', 'meta_user_id')) {
            return;
        }

        Schema::table('company_integrations', function (Blueprint $table) {
            $table->string('meta_user_id', 64)->nullable()->after('access_token')->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('company_integrations', 'meta_user_id')) {
            return;
        }

        Schema::table('company_integrations', function (Blueprint $table) {
            $table->dropIndex(['meta_user_id']);
            $table->dropColumn('meta_user_id');
        });
    }
};
