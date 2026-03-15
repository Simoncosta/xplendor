<?php

namespace App\Console\Commands;

use App\Jobs\CalculateCarSalePotentialScoreJob;
use App\Jobs\RecalculateAllCarScoresJob;
use Illuminate\Console\Command;

class CalculateCarScoreCommand extends Command
{
    protected $signature = 'ips:calculate
                                {--car=  : ID do carro específico (omitir = todos os activos)}
                                {--company= : ID da empresa (obrigatório se --car for usado)}
                                {--sync  : Executar de forma síncrona}';

    protected $description = 'Calcula o Índice de Potencial de Venda (IPS)';

    public function handle(): int
    {
        $carId     = $this->option('car');
        $companyId = $this->option('company');
        $sync      = $this->option('sync');

        if ($carId) {
            if (! $companyId) {
                $this->error('--company é obrigatório quando --car é especificado.');
                return Command::FAILURE;
            }

            $this->info("A calcular IPS para carro #{$carId}...");

            $sync
                ? CalculateCarSalePotentialScoreJob::dispatchSync((int)$carId, (int)$companyId, 'manual')
                : CalculateCarSalePotentialScoreJob::dispatch((int)$carId, (int)$companyId, 'manual');
        } else {
            $this->info('A calcular IPS para todos os carros activos...');

            $sync
                ? RecalculateAllCarScoresJob::dispatchSync()
                : RecalculateAllCarScoresJob::dispatch();
        }

        $this->info($sync ? 'Concluído!' : 'Jobs enviados para a queue.');
        return Command::SUCCESS;
    }
}
