<?php

namespace App\Console\Commands;

use App\Services\MetaExpenseProjector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Projeta as despesas automáticas do gasto Meta (uma por viatura e por mês, mais o
 * stock geral e o "por atribuir"). Idempotente. Arranque no deploy:
 *
 *   php artisan meta:project-expenses --months=14          # 13 meses + o atual, todas as empresas
 *   php artisan meta:project-expenses --company=12 --months=2
 */
class ProjectMetaExpenses extends Command
{
    protected $signature = 'meta:project-expenses
                            {--months=2 : Quantos meses (o atual incluído)}
                            {--company= : Só esta empresa}';

    protected $description = 'Projeta o gasto Meta por viatura nas Despesas (automáticas, mensais)';

    public function handle(MetaExpenseProjector $projector): int
    {
        $months = MetaExpenseProjector::recentMonths(max(1, min(37, (int) $this->option('months'))));

        $companies = $this->option('company')
            ? collect([(int) $this->option('company')])
            : DB::table('campaign_car_metrics_daily')->distinct()->pluck('company_id')
                ->merge(DB::table('meta_ad_insights_daily')->distinct()->pluck('company_id'))
                ->map(fn ($id) => (int) $id)->unique()->values();

        foreach ($companies as $companyId) {
            $summary = $projector->project($companyId, $months);
            $lines = array_sum(array_column($summary, 'lines'));
            $written = array_sum(array_column($summary, 'written'));
            $deleted = array_sum(array_column($summary, 'deleted'));
            $this->line("empresa {$companyId}: {$lines} linha(s) · {$written} escrita(s) · {$deleted} apagada(s)");
        }

        $this->info('Meses: ' . implode(', ', $months));

        return self::SUCCESS;
    }
}
