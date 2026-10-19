<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Restaurant\Forecast\FutureReservationsService;
use App\Services\Restaurant\Forecast\RestaurantForecastService;
use Illuminate\Console\Command;

/**
 * Fatia 6a: lê as reservas futuras (CoverManager, SÓ LEITURA) e calcula a previsão e o erro medido.
 *   php artisan restaurant:forecast 5
 *   php artisan restaurant:forecast 5 --sem-reservas   (só a previsão, sem ler o CoverManager)
 */
class RestaurantForecastCommand extends Command
{
    protected $signature = 'restaurant:forecast {company : ID da empresa} {--sem-reservas : Não ler o CoverManager}';

    protected $description = 'Reservas futuras, previsão por loja e dia e erro medido (backtest de 8 semanas).';

    public function handle(FutureReservationsService $reservations): int
    {
        $c = (int) $this->argument('company');
        if (! $this->option('sem-reservas')) {
            $r = $reservations->sync($c);
            $this->info(sprintf('Reservas: %d lojas, %d dias%s', $r['locations'], $r['days'], $r['failed'] ? ' · falharam: ' . implode(', ', $r['failed']) : ''));
        }
        foreach ((new RestaurantForecastService($c))->run() as $loc => $x) {
            foreach ($x['errors'] as $m => $models) {
                $this->line(sprintf('Loja %d · %s · %s', $loc, $m, implode(' · ', array_map(fn ($k, $v) => sprintf('%s %s%% (n=%d)', $k, number_format((float) $v['mape'], 1, ',', ''), $v['n']), array_keys($models), $models))));
            }
        }

        return self::SUCCESS;
    }
}
