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
 *   php artisan meta:backfill-account-insights           # as que nunca fizeram backfill ou o têm mais curto (ex.: 90 dias)
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

    protected $description = 'Enfileira o backfill (13 meses) da ingestão Meta ao nível da conta';

    public function handle(MetaAccountInsightsService $service): int
    {
        $query = CompanyIntegration::platform('meta')->where('status', '!=', 'revoked');

        if ($this->option('company')) {
            $query->where('company_id', (int) $this->option('company'));
        }
        if (! $this->option('all')) {
            // Nunca fizeram backfill, OU fizeram com uma profundidade menor que a
            // actual (o antigo de 90 dias fica com insights_backfill_months = null).
            $query->where(function ($q) {
                $q->whereNull('insights_backfilled_at')
                    ->orWhereNull('insights_backfill_months')
                    ->orWhere('insights_backfill_months', '<', MetaAccountInsightsService::BACKFILL_MONTHS);
            });
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
