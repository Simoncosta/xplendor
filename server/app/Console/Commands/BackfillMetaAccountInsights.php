<?php

namespace App\Console\Commands;

use App\Models\CompanyIntegration;
use App\Services\MetaAccountInsightsService;
use Illuminate\Console\Command;

/**
 * Arranque da ingestão Meta AO NÍVEL DA CONTA para integrações que JÁ existiam
 * antes do deploy (a migração deixa-as sem estado e nada as enfileirava até ao
 * despachante das 01:15). Correr UMA vez no deploy:
 *
 *   php artisan meta:backfill-account-insights           # só as que nunca fizeram backfill
 *   php artisan meta:backfill-account-insights --all     # força re-backfill de todas
 *   php artisan meta:backfill-account-insights --company=12
 *
 * Idempotente: o job tem WithoutOverlapping por integração e substitui a janela.
 */
class BackfillMetaAccountInsights extends Command
{
    protected $signature = 'meta:backfill-account-insights
                            {--all : Re-faz o backfill mesmo das que já o concluíram}
                            {--company= : Só esta empresa}';

    protected $description = 'Enfileira o backfill de 90 dias da ingestão Meta ao nível da conta';

    public function handle(MetaAccountInsightsService $service): int
    {
        $query = CompanyIntegration::platform('meta')->where('status', '!=', 'revoked');

        if ($this->option('company')) {
            $query->where('company_id', (int) $this->option('company'));
        }
        if (! $this->option('all')) {
            $query->whereNull('insights_backfilled_at');
        }

        $queued = 0;
        $skipped = 0;

        foreach ($query->get() as $integration) {
            $service->scheduleBackfill($integration);
            $fresh = $integration->fresh();

            if ($fresh->insights_sync_status === MetaAccountInsightsService::STATUS_PENDING) {
                $queued++;
                $this->line("✓ empresa {$integration->company_id}: backfill enfileirado");
            } else {
                $skipped++;
                $this->line("· empresa {$integration->company_id}: {$fresh->insights_sync_status} (sem backfill)");
            }
        }

        $this->info("Backfills enfileirados: {$queued} · ignorados: {$skipped}");

        return self::SUCCESS;
    }
}
