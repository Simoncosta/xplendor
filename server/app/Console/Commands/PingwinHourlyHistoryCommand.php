<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\PingwinHourlySalesDay;
use App\Models\PingwinLocation;
use App\Services\PingwinItemHistoryService;
use App\Services\PingwinItemSalesService;
use Illuminate\Console\Command;

/**
 * XPLENDOR — F2, MANUAL: importa o histórico das vendas por hora em blocos de 7 dias, 20 s
 * entre pedidos, até ao primeiro dia com vendas de cada loja (precisa do histórico por
 * artigo completo). Só leitura no PingWin; exige o interruptor da empresa ligado.
 *
 * ⚠️ Corre num contentor à parte do worker, a partir da raiz do repositório:
 *   docker compose run --rm --no-deps worker php artisan pingwin:hourly-history 5 --calls=40
 */
class PingwinHourlyHistoryCommand extends Command
{
    protected $signature = 'pingwin:hourly-history
        {company : ID da empresa (ex.: 5 = Yuko)}
        {--calls=10 : Pedidos de histórico nesta execução (máximo 60)}';

    protected $description = 'Importa o histórico das vendas por hora do PingWin até ao primeiro dia com vendas de cada loja.';

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
        $this->line("A importar o histórico por hora (até {$calls} pedidos de 7 dias, 20 s entre eles)…");

        $started = microtime(true);
        try {
            $result = $history->backfillHourly($companyId, $calls);
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
            $this->info($result['complete'] ? 'Nada por importar (histórico por hora completo).' : 'Falta o histórico por artigo (o primeiro dia com vendas de cada loja).');
        } else {
            $this->line("Chegou a {$result['reached']}; alvo {$result['target']}.");
            $result['complete'] ? $this->info('Histórico por hora completo.') : $this->warn('Histórico por hora por acabar: volte a correr o comando.');
        }

        $this->table(['Loja', '1.º dia com vendas', 'Dias lidos (horas)', 'Histórico por hora'],
            PingwinLocation::where('company_id', $companyId)->where('is_active', true)->orderBy('id')->get()
                ->map(fn (PingwinLocation $l) => [
                    $l->display_name ?: $l->winrest_store_id,
                    $l->sales_since?->toDateString() ?? '',
                    PingwinHourlySalesDay::where('location_id', $l->id)->count(),
                    $l->hourly_history_complete_at ? 'completo' : 'por acabar',
                ])->all());

        return self::SUCCESS;
    }
}
