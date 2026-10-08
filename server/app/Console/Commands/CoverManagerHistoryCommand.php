<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\CoverManagerService;
use App\Services\PingwinItemSalesService;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;

/**
 * XPLENDOR — F2, MANUAL e só numa sessão acompanhada: relê as reservas do CoverManager dos
 * últimos dias (por omissão 90), um dia de cada vez, com espaçamento, para preencher os
 * agregados por hora, canal, antecedência e código de estado. É a mesma leitura do job das
 * 05:00 (get_reservs, uma por loja e por dia); nada de dados pessoais fica guardado. Exige o
 * interruptor da empresa ligado.
 *
 * Corre a partir da raiz do repositório (não precisa do socket Docker, mas é longo):
 *   docker compose run --rm --no-deps worker php artisan covermanager:history 5 --days=90
 */
class CoverManagerHistoryCommand extends Command
{
    /** Segundos entre dias (cada dia faz um pedido por loja). */
    public const SPACING_SECONDS = 5;

    protected $signature = 'covermanager:history
        {company : ID da empresa (ex.: 5 = Yuko)}
        {--days=90 : Quantos dias para trás, a acabar ontem (máximo 90)}';

    protected $description = 'Relê as reservas do CoverManager dos últimos dias para os agregados por hora, canal, antecedência e estado.';

    public function handle(CoverManagerService $cover): int
    {
        $companyId = (int) $this->argument('company');
        $company = Company::find($companyId);
        if (! $company) {
            $this->error("Empresa {$companyId} não existe.");

            return self::FAILURE;
        }
        if (! PingwinItemSalesService::isEnabled($companyId)) {
            $this->error("O interruptor está desligado: ligue-o com pingwin:item-sales-switch {$companyId} on.");

            return self::FAILURE;
        }
        if ($cover->syncableLocations($companyId, $cover->companyToken($companyId))->isEmpty()) {
            $this->error('Sem lojas com CoverManager (token e slug) nesta empresa.');

            return self::FAILURE;
        }

        $days = max(1, min(90, (int) $this->option('days')));
        $to = CarbonImmutable::yesterday();
        $from = $to->subDays($days - 1);
        $this->line("Empresa: {$company->fiscal_name} ({$companyId})");
        $this->line("Reservas de {$from->toDateString()} a {$to->toDateString()} ({$days} dias, " . self::SPACING_SECONDS . ' s entre dias)…');

        $started = microtime(true);
        $failedDays = [];
        $totals = ['reservations' => 0, 'guests' => 0, 'cancelled' => 0, 'no_shows' => 0];
        $i = 0;
        foreach (CarbonPeriod::create($from, $to) as $day) {
            if ($i++ > 0) {
                Sleep::for(self::SPACING_SECONDS)->seconds();
            }
            $result = $cover->sync($companyId, $day->toDateString());
            if (! empty($result['failed'])) {
                $failedDays[] = $day->toDateString() . ' (' . implode(', ', $result['failed']) . ')';
            }
            foreach ($result['results'] as $r) {
                foreach ($r['shifts'] as $s) {
                    $totals['reservations'] += $s['reservations_count'];
                    $totals['guests'] += $s['guests_total'];
                    $totals['cancelled'] += $s['cancelled_count'];
                    $totals['no_shows'] += $s['no_show_count'] ?? 0;
                }
            }
        }

        $this->line(sprintf('Reservas válidas: %d; pessoas: %d; anuladas: %d; faltas: %d.',
            $totals['reservations'], $totals['guests'], $totals['cancelled'], $totals['no_shows']));
        $this->line('Tempo: ' . round(microtime(true) - $started) . ' s.');
        if ($failedDays !== []) {
            $this->warn('Dias com falhas: ' . implode('; ', $failedDays));
        }
        $this->info('Concluído: agregados das reservas gravados.');

        return self::SUCCESS;
    }
}
