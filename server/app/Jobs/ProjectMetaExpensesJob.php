<?php

namespace App\Jobs;

use App\Services\MetaExpenseProjector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Despesas automáticas do gasto Meta, todos os dias (rede de segurança): mês atual
 * e anterior (a Meta revê os últimos dias, também na viragem do mês) para todas as
 * empresas com gasto Meta por viatura ou por anúncio, incluindo as que só têm o
 * mapeamento manual antigo (sem ingestão por anúncio).
 */
class ProjectMetaExpensesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 85;

    public function handle(MetaExpenseProjector $projector): void
    {
        $months = MetaExpenseProjector::recentMonths(2);

        $companies = DB::table('campaign_car_metrics_daily')->distinct()->pluck('company_id')
            ->merge(DB::table('meta_ad_insights_daily')->distinct()->pluck('company_id'))
            ->merge(DB::table('expenses')->where('source', 'meta_ads')->distinct()->pluck('company_id'))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        foreach ($companies as $companyId) {
            try {
                $projector->project($companyId, $months);
            } catch (\Throwable $e) {
                Log::warning('ProjectMetaExpensesJob: empresa não projetada', [
                    'company_id' => $companyId,
                    'error'      => $e->getMessage(),
                ]);
            }
        }
    }
}
