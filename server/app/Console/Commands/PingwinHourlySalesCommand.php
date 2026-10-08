<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\PingwinHourlySalesService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * XPLENDOR — F2, MANUAL: lê as vendas por hora do PingWin (só leitura) e confere a soma das
 * horas com o líquido diário. Com --dry-run não grava nada; gravar exige o interruptor da
 * empresa ligado (pingwin:item-sales-switch).
 *
 * ⚠️ Precisa do socket Docker (o invoke() faz `docker exec` ao scraper) e pode demorar:
 * corre num contentor à parte do worker, que não reinicia de hora a hora como o
 * xplendor-worker (queue:work --max-time=3600), a partir da raiz do repositório:
 *   docker compose run --rm --no-deps worker php artisan pingwin:hourly-sales 5 --dry-run
 */
class PingwinHourlySalesCommand extends Command
{
    protected $signature = 'pingwin:hourly-sales
        {company : ID da empresa (ex.: 5 = Yuko)}
        {--from= : Primeiro dia (AAAA-MM-DD); por omissão, 7 dias antes de ontem}
        {--to= : Último dia (AAAA-MM-DD); por omissão, ontem}
        {--dry-run : Lê e confere, mas não grava nada}';

    protected $description = 'Lê as vendas por hora do PingWin (até 31 dias, blocos de 7) e confere-as com o líquido diário.';

    public function handle(PingwinHourlySalesService $service): int
    {
        $companyId = (int) $this->argument('company');
        $company = Company::find($companyId);
        if (! $company) {
            $this->error("Empresa {$companyId} não existe.");

            return self::FAILURE;
        }

        [$defaultFrom, $defaultTo] = PingwinHourlySalesService::nightlyWindow();
        $from = (string) ($this->option('from') ?: $defaultFrom);
        $to = (string) ($this->option('to') ?: $defaultTo);
        if ($this->option('from') && ! $this->option('to')) {
            $to = CarbonImmutable::parse($from)->addDays(PingwinHourlySalesService::DAYS_PER_CALL - 1)
                ->min(CarbonImmutable::yesterday())->toDateString();
        }
        $dryRun = (bool) $this->option('dry-run');
        $enabled = PingwinHourlySalesService::isEnabled($companyId);

        $this->line("Empresa: {$company->fiscal_name} ({$companyId})");
        $this->line('Interruptor: ' . ($enabled ? 'ligado' : 'desligado'));
        $this->line("Período: {$from} a {$to}" . ($dryRun ? ' (simulação: nada é gravado)' : ''));
        if (! $dryRun && ! $enabled) {
            $this->error("O interruptor está desligado: use --dry-run, ou ligue-o com pingwin:item-sales-switch {$companyId} on.");

            return self::FAILURE;
        }

        $started = microtime(true);
        try {
            $result = $service->sync($companyId, $from, $to, $dryRun);
        } catch (\Throwable $e) {
            $this->error('Falhou: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Loja', 'Dia', 'Horas com vendas', 'Soma das horas', 'Líquido diário', 'Diferença', 'Estado'],
            array_map(fn ($d) => [
                $d['location'], $d['date'], $d['rows'], self::eur($d['items_net_cents']),
                $d['daily_net_cents'] === null ? '(sem resumo)' : self::eur($d['daily_net_cents']),
                $d['daily_net_cents'] === null ? '' : self::eur($d['items_net_cents'] - $d['daily_net_cents']),
                PingwinItemSalesCommand::label($d['status']) . (($d['daily_reread'] ?? false) ? ' (resumo relido)' : ''),
            ], $result['days'])
        );
        $total = array_sum($result['hours']);
        if ($total > 0) {
            $hours = $result['hours'];
            ksort($hours);
            $this->line('Peso de cada hora (sem IVA): ' . implode('  ', array_map(
                fn ($h, $c) => $h . ' ' . number_format($c / $total * 100, 1, ',', ' ') . '%', array_keys($hours), $hours)));
        }
        $counts = array_count_values(array_column($result['days'], 'status'));
        $this->line('Pedidos ao PingWin: ' . $result['calls'] . '; tempo: ' . round(microtime(true) - $started, 1) . ' s.');
        $this->line('Estados: ' . implode(', ', array_map(fn ($s, $n) => PingwinItemSalesCommand::label($s) . " {$n}", array_keys($counts), $counts)));
        $this->info($dryRun ? 'Simulação concluída: nada foi gravado.' : 'Concluído: vendas por hora gravadas.');

        return self::SUCCESS;
    }

    private static function eur(int $cents): string
    {
        return number_format($cents / 100, 2, ',', ' ') . ' €';
    }
}
