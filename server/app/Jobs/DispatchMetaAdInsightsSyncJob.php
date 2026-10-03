<?php

namespace App\Jobs;

use App\Models\CompanyIntegration;
use App\Services\MetaAccountInsightsService;
use App\Services\MetaAdInsightsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Despachante DIÁRIO da ingestão Meta por anúncio. Regista sempre o estado,
 * incluindo das integrações que não podem sincronizar (token expirado, sem conta).
 * Quem ainda não fez o backfill sobe a backfill no próprio sync.
 */
class DispatchMetaAdInsightsSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        CompanyIntegration::platform('meta')
            ->where('status', '!=', 'revoked')
            ->get()
            ->each(function (CompanyIntegration $integration) {
                if ($integration->status === 'expired' || $integration->isTokenExpired()) {
                    $integration->update([
                        'ad_insights_sync_status' => MetaAdInsightsService::STATUS_TOKEN_EXPIRED,
                        'ad_insights_last_run_at' => now(),
                        'ad_insights_error'       => 'Sessão Meta expirada. Reconecta a conta.',
                    ]);

                    return;
                }

                if (MetaAccountInsightsService::normalizeAccountId($integration->account_id) === null) {
                    $integration->update([
                        'ad_insights_sync_status' => MetaAdInsightsService::STATUS_NEEDS_ACCOUNT,
                        'ad_insights_last_run_at' => now(),
                    ]);

                    return;
                }

                SyncMetaAdInsightsJob::dispatch($integration->id, MetaAdInsightsService::MODE_DAILY);
            });
    }
}
