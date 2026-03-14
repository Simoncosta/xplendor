<?php

namespace App\Console\Commands;

use App\Jobs\AggregateCarPerformanceMetricsJob;
use Illuminate\Console\Command;

class AggregatePerformanceMetricsCommand extends Command
{
    protected $signature = 'performance:aggregate
                                {--from= : Data de início (Y-m-d). Omitir = ontem}
                                {--to=   : Data de fim   (Y-m-d). Omitir = ontem}
                                {--sync  : Executar de forma síncrona sem queue}';

    protected $description = 'Agrega car_views e car_leads em car_performance_metrics';

    public function handle(): int
    {
        $from = $this->option('from');
        $to   = $this->option('to');
        $sync = $this->option('sync');

        $this->info('A agregar performance metrics...');
        $this->info('Período: ' . ($from ?? 'ontem') . ' → ' . ($to ?? 'ontem'));

        if ($sync) {
            AggregateCarPerformanceMetricsJob::dispatchSync($from, $to);
            $this->info('Concluído!');
        } else {
            AggregateCarPerformanceMetricsJob::dispatch($from, $to);
            $this->info('Job enviado para a queue.');
        }

        return Command::SUCCESS;
    }
}
