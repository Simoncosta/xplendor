<?php

namespace App\Console\Commands;

use App\Models\CompanyIntegration;
use App\Services\MetaAdInsightsService;
use Illuminate\Console\Command;

/**
 * Arranque da ingestão Meta POR ANÚNCIO (13 meses, mês a mês) para as integrações
 * que já existiam antes do deploy. Correr UMA vez no deploy:
 *
 *   php artisan meta:backfill-ad-insights               # as que nunca fizeram o backfill por anúncio
 *   php artisan meta:backfill-ad-insights --all         # recomeça o backfill de todas (do 1.º mês)
 *   php artisan meta:backfill-ad-insights --company=12
 *
 * Idempotente: um job por integração (WithoutOverlapping), um mês por execução,
 * cada mês substitui a sua janela.
 */
class BackfillMetaAdInsights extends Command
{
    protected $signature = 'meta:backfill-ad-insights
                            {--all : Recomeça o backfill mesmo das que já o concluíram}
                            {--company= : Só esta empresa}';

    protected $description = 'Enfileira o backfill (13 meses, mês a mês) da ingestão Meta por anúncio';

    public function handle(MetaAdInsightsService $service): int
    {
        $query = CompanyIntegration::platform('meta')->where('status', '!=', 'revoked');

        if ($this->option('company')) {
            $query->where('company_id', (int) $this->option('company'));
        }
        if (! $this->option('all')) {
            $query->whereNull('ad_insights_backfilled_at');
        }

        $queued = 0;
        $skipped = 0;

        foreach ($query->get() as $integration) {
            if ($this->option('all')) {
                $integration->update(['ad_insights_backfilled_at' => null, 'ad_insights_backfill_cursor' => null]);
            }

            $service->schedule($integration, MetaAdInsightsService::MODE_BACKFILL);
            $fresh = $integration->fresh();

            if ($fresh->ad_insights_sync_status === MetaAdInsightsService::STATUS_PENDING) {
                $queued++;
                $this->line("✓ empresa {$integration->company_id}: backfill por anúncio enfileirado");
            } else {
                $skipped++;
                $this->line("· empresa {$integration->company_id}: {$fresh->ad_insights_sync_status} (sem backfill)");
            }
        }

        $this->info("Backfills enfileirados: {$queued} · ignorados: {$skipped}");

        return self::SUCCESS;
    }
}
