<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\PingwinItemSalesDay;
use App\Models\PingwinLocation;
use App\Services\PingwinItemHistoryService;
use App\Services\PingwinItemSalesService;
use Illuminate\Console\Command;

/**
 * XPLENDOR — F1-2, MANUAL: deteta o início de cada loja (relatório anual) e importa o
 * histórico das vendas por artigo em blocos de 7 dias, 20 s entre pedidos (só leitura
 * no PingWin). Exige o interruptor da empresa ligado.
 *
 * ⚠️ Corre no worker (docker socket):
 *   docker exec xplendor-worker php artisan pingwin:item-history 5 --detect-only
 *   docker exec xplendor-worker php artisan pingwin:item-history 5 --calls=40
 */
class PingwinItemHistoryCommand extends Command
{
    protected $signature = 'pingwin:item-history
        {company : ID da empresa (ex.: 5 = Yuko)}
        {--detect-only : Só deteta o início de cada loja}
        {--redetect : Volta a detetar o início das lojas já detetadas}
        {--locals= : Postos de venda a pedir no relatório anual (IDs separados por vírgulas)}
        {--calls=10 : Pedidos de histórico nesta execução (máximo 60)}';

    protected $description = 'Deteta o início de cada loja e importa o histórico das vendas por artigo do PingWin.';

    public function handle(PingwinItemHistoryService $history): int
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
        $calls = max(1, min(60, (int) $this->option('calls')));
        $this->line("Empresa: {$company->fiscal_name} ({$companyId})");

        $started = microtime(true);
        try {
            $needsDetection = $this->option('redetect')
                || PingwinLocation::where('company_id', $companyId)->where('is_active', true)->whereNull('sales_start_checked_at')->exists();
            if ($needsDetection) {
                $this->line('A detetar o início de cada loja (relatório anual, uma loja e um ano de cada vez)…');
                $detected = $history->detectStarts($companyId, (string) $this->option('locals'), (bool) $this->option('redetect'));
                foreach ($detected as $d) {
                    $this->line(sprintf('  %s: primeiro mês com vendas %s (anos lidos: %s)',
                        $d['location'], $d['first_month'] ?? 'nenhum', implode(', ', array_keys($d['years']))));
                    foreach ($d['years'] as $year => $months) {
                        $this->line('    ' . $year . ': ' . implode(' ', array_map(fn ($v) => number_format($v, 0, ',', ' '), $months)));
                    }
                }
            }
            if ($this->option('detect-only')) {
                $this->info('Deteção concluída.');

                return self::SUCCESS;
            }

            $this->line("A importar o histórico (até {$calls} pedidos de 7 dias, 20 s entre eles)…");
            $result = $history->backfill($companyId, $calls);
        } catch (\Throwable $e) {
            $this->error('Falhou: ' . $e->getMessage());

            return self::FAILURE;
        }

        foreach ($result['blocks'] as $b) {
            $this->line(sprintf('  %s a %s: %s', $b['from'], $b['to'],
                implode(', ', array_map(fn ($s, $n) => "{$s} {$n}", array_keys($b['statuses']), $b['statuses']))));
        }
        $this->line('Pedidos: ' . $result['calls'] . '; tempo: ' . round(microtime(true) - $started) . ' s.');
        if ($result['target'] === null) {
            $this->info('Nada por importar (histórico completo ou início por detetar).');
        } else {
            $this->line("Chegou a {$result['reached']}; alvo {$result['target']}.");
            $result['complete']
                ? $this->info('Histórico completo.')
                : $this->warn('Histórico por acabar: volte a correr o comando (ou deixe o job das 05:00 continuar).');
        }

        $this->table(['Loja', 'Abertura (manual)', '1.º mês detetado', '1.º dia com vendas', 'Dias lidos', 'Histórico'],
            PingwinLocation::where('company_id', $companyId)->where('is_active', true)->orderBy('id')->get()
                ->map(fn (PingwinLocation $l) => [
                    $l->display_name ?: $l->winrest_store_id,
                    $l->opened_on?->toDateString() ?? '',
                    $l->sales_first_month?->toDateString() ?? '',
                    $l->sales_since?->toDateString() ?? '',
                    PingwinItemSalesDay::where('location_id', $l->id)->count(),
                    $l->history_complete_at ? 'completo' : 'por acabar',
                ])->all());

        return self::SUCCESS;
    }
}
