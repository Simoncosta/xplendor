<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Estado da leitura dos públicos personalizados (Meta). Separado do
 * sync de insights: ler públicos pode falhar por PERMISSÃO (ads_read pode não
 * chegar) sem que os insights falhem. O ecrã mostra o estado com honestidade.
 *   audiences_sync_status  ok | no_permission | failed
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_integrations', function (Blueprint $table) {
            $table->string('audiences_sync_status', 20)->nullable()->after('insights_synced_until');
            $table->timestamp('audiences_synced_at')->nullable()->after('audiences_sync_status');
            $table->text('audiences_error')->nullable()->after('audiences_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('company_integrations', function (Blueprint $table) {
            $table->dropColumn(['audiences_sync_status', 'audiences_synced_at', 'audiences_error']);
        });
    }
};
