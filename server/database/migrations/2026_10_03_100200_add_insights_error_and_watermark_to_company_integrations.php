<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Meta Ads (ingestão ao nível da conta): colunas PRÓPRIAS do sync por
 * conta. error_message/last_synced_at são partilhados com o job por carro, que a
 * cada 30 min os repõe (apagava o erro do sync por conta). E a "marca d'água"
 * insights_synced_until permite ao diário recuperar dias perdidos se o sync falhar
 * vários dias seguidos (em vez de deixar buracos a zero para sempre).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_integrations', function (Blueprint $table) {
            $table->text('insights_error')->nullable()->after('insights_last_run_at');
            $table->timestamp('insights_synced_at')->nullable()->after('insights_error');
            $table->date('insights_synced_until')->nullable()->after('insights_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('company_integrations', function (Blueprint $table) {
            $table->dropColumn(['insights_error', 'insights_synced_at', 'insights_synced_until']);
        });
    }
};
