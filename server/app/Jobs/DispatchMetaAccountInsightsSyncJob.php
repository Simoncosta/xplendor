<?php

namespace App\Jobs;

use App\Models\CompanyIntegration;
use App\Services\MetaAccountInsightsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Despachante DIÁRIO da ingestão Meta ao nível da conta. Passa por TODAS as
 * integrações Meta não revogadas e REGISTA SEMPRE o estado — incluindo as que não
 * podem sincronizar (token expirado, conta por escolher). O job por carro saltava
 * empresas sem deixar rasto; aqui nunca fica "parado" sem explicação.
 */
class DispatchMetaAccountInsightsSyncJob implements ShouldQueue
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
                        'status'               => 'expired',
                        'insights_sync_status' => MetaAccountInsightsService::STATUS_TOKEN_EXPIRED,
                        'insights_last_run_at' => now(),
                        'insights_error'       => 'Sessão Meta expirada. Reconecta a conta.',
                    ]);

                    return;
                }

                if (MetaAccountInsightsService::normalizeAccountId($integration->account_id) === null) {
                    $integration->update([
                        'insights_sync_status' => MetaAccountInsightsService::STATUS_NEEDS_ACCOUNT,
                        'insights_last_run_at' => now(),
                    ]);

                    return;
                }

                SyncMetaAccountInsightsJob::dispatch($integration->id, MetaAccountInsightsService::MODE_DAILY);
            });
    }
}
