<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RefreshStaleMarketAggregatesJob;
use App\Services\MarketSnapshotService;
use Illuminate\Console\Command;

class RefreshStaleMarketAggregatesCommand extends Command
{
    protected $signature = 'market-aggregates:refresh-stale
                                {--dry-run : Listar viaturas elegíveis sem despachar jobs}';

    protected $description = 'Refresca aggregates de mercado para viaturas sem dados ou com dados desactualizados (>7 dias)';

    public function handle(MarketSnapshotService $service): int
    {
        $job  = new RefreshStaleMarketAggregatesJob();
        $cars = $job->resolveStaleCars();

        $this->info("Viaturas elegíveis para refresh: {$cars->count()}");

        if ($cars->isEmpty()) {
            $this->line('Nenhuma viatura precisa de refresh neste momento.');
            return self::SUCCESS;
        }

        $headers = ['ID', 'Marca', 'Modelo', 'Ano', 'Tipo', 'Status', 'Último aggregate'];
        $rows    = $cars->map(function ($car) {
            $agg        = $car->latestMarketAggregate;
            $lastUpdate = $agg ? $agg->updated_at->diffForHumans() : 'nunca';

            return [
                $car->id,
                $car->brand?->name ?? '—',
                $car->model?->name ?? '—',
                $car->registration_year,
                $car->vehicle_type,
                $car->status,
                $lastUpdate,
            ];
        })->toArray();

        $this->table($headers, $rows);

        if ($this->option('dry-run')) {
            $this->warn('--dry-run activo: nenhum job foi despachado.');
            return self::SUCCESS;
        }

        if (!$this->confirm("Despachar {$cars->count()} job(s) de refresh?", true)) {
            $this->line('Cancelado.');
            return self::SUCCESS;
        }

        foreach ($cars as $index => $car) {
            $service->snapshotForCar($car);
            $this->line("  ✓ Despachado: {$car->brand?->name} {$car->model?->name} {$car->registration_year} (ID {$car->id})");

            if ($index < $cars->count() - 1) {
                sleep(15);
            }
        }

        $this->info("Concluído. {$cars->count()} job(s) despachados.");

        return self::SUCCESS;
    }
}
