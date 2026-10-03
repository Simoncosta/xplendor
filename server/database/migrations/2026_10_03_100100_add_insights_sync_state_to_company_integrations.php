<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Meta Ads: estado REAL da sincronização ao nível da conta, para o
 * ecrã mostrar a verdade em vez de zeros confusos.
 *
 *   insights_sync_status   pending|running|done|failed|needs_account|token_expired
 *   insights_backfilled_at 1.º backfill (90 dias) concluído; null = nunca → "a
 *                          sincronizar pela primeira vez"
 *   insights_last_run_at   CADA tentativa (com ou sem sucesso) — o sync nunca
 *                          volta a ficar "parado" sem rasto.
 *
 * Só colunas nullable (aditivo; não toca no enum de status).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_integrations', function (Blueprint $table) {
            $table->string('insights_sync_status', 20)->nullable()->after('last_synced_at');
            $table->timestamp('insights_backfilled_at')->nullable()->after('insights_sync_status');
            $table->timestamp('insights_last_run_at')->nullable()->after('insights_backfilled_at');
        });
    }

    public function down(): void
    {
        Schema::table('company_integrations', function (Blueprint $table) {
            $table->dropColumn(['insights_sync_status', 'insights_backfilled_at', 'insights_last_run_at']);
        });
    }
};
