<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado PRÓPRIO da ingestão Meta por anúncio (independente da ingestão por conta).
 * ad_insights_backfill_cursor = 1.º dia do próximo mês a buscar no backfill mês a
 * mês (retoma onde parou se um mês falhar); ad_insights_account_id = conta a que os
 * dados guardados pertencem (se a conta mudar, os dados antigos apagam-se).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_integrations', function (Blueprint $table) {
            $table->string('ad_insights_sync_status', 20)->nullable();
            $table->string('ad_insights_account_id', 50)->nullable();
            $table->date('ad_insights_backfill_cursor')->nullable();
            $table->timestamp('ad_insights_backfilled_at')->nullable();
            $table->date('ad_insights_synced_until')->nullable();
            $table->timestamp('ad_insights_synced_at')->nullable();
            $table->timestamp('ad_insights_last_run_at')->nullable();
            $table->string('ad_insights_error', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('company_integrations', function (Blueprint $table) {
            $table->dropColumn([
                'ad_insights_sync_status',
                'ad_insights_account_id',
                'ad_insights_backfill_cursor',
                'ad_insights_backfilled_at',
                'ad_insights_synced_until',
                'ad_insights_synced_at',
                'ad_insights_last_run_at',
                'ad_insights_error',
            ]);
        });
    }
};
