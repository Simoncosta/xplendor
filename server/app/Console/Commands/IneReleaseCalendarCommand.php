<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Radar\Calendar\IneReleaseCalendar;
use Illuminate\Console\Command;

/**
 * Bússola 6: as datas de publicação do INE que o calendário da Bússola segue (preços da habitação ao
 * nível local, juros implícitos no crédito à habitação, avaliação bancária), lidas no calendário de
 * difusão (.ics). A leitura real pede o endereço (INE_CALENDAR_URL) e as chamadas externas ligadas.
 *
 *   php artisan ine:release-calendar                          diz o que faria (sem pedidos)
 *   php artisan ine:release-calendar --execute                lê o INE e grava
 *   php artisan ine:release-calendar --fixture=caminho.ics    lê uma amostra gravada (com --execute grava)
 */
class IneReleaseCalendarCommand extends Command
{
    protected $signature = 'ine:release-calendar {--execute : Lê e grava (sem isto, só diz o que faria)} {--fixture= : Lê esta amostra gravada (.ics)}';

    protected $description = 'Bússola: as datas de publicação do INE seguidas pelo calendário (calendário de difusão).';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $fixture = $this->option('fixture');
        if ($fixture === null && IneReleaseCalendar::url() === '') {
            $this->info('Sem endereço do calendário do INE (INE_CALENDAR_URL): nada a ler.');

            return self::SUCCESS;
        }
        if ($fixture === null && ! $execute) {
            $this->line('SIMULAÇÃO: nenhum pedido. Com --execute, lê ' . IneReleaseCalendar::url());

            return self::SUCCESS;
        }
        if ($fixture !== null) {
            $path = is_file((string) $fixture) ? (string) $fixture : base_path((string) $fixture);
            if (! is_file($path)) {
                $this->error("Amostra não encontrada: {$fixture}");

                return self::FAILURE;
            }
            $ics = (string) file_get_contents($path);
        } else {
            $ics = IneReleaseCalendar::fetch();
            if ($ics === null) {
                $this->warn('Não foi possível ler o calendário do INE (chamadas externas desligadas ou erro na resposta).');

                return self::FAILURE;
            }
        }
        $rows = IneReleaseCalendar::parse($ics);
        $this->table(['Publicação', 'Data'], array_map(fn ($r) => [$r['source_key'], $r['starts_on']], $rows));
        if (! $execute) {
            $this->line('SIMULAÇÃO: nada foi gravado. Use --execute para gravar.');

            return self::SUCCESS;
        }
        $this->info('Gravadas ' . IneReleaseCalendar::save($rows) . ' datas.');

        return self::SUCCESS;
    }
}
