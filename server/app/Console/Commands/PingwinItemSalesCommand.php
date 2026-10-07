<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\PingwinItemSalesService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * XPLENDOR — Leitura MANUAL das vendas por artigo do PingWin (F1-1 do marketing, só
 * leitura no PingWin). Com --dry-run lê e confere mas não grava nada; gravar exige o
 * interruptor da empresa ligado (pingwin:item-sales-switch).
 *
 * ⚠️ O invoke() faz `docker exec` ao container do scraper → este comando TEM de
 * correr no container QUE TEM o socket Docker (o worker):
 *   docker exec xplendor-worker php artisan pingwin:item-sales 5 --dry-run
 */
class PingwinItemSalesCommand extends Command
{
    protected $signature = 'pingwin:item-sales
        {company : ID da empresa (ex.: 5 = Yuko)}
        {--from= : Primeiro dia (AAAA-MM-DD); por omissão, 7 dias antes de ontem}
        {--to= : Último dia (AAAA-MM-DD); por omissão, ontem}
        {--dry-run : Lê e confere, mas não grava nada}';

    protected $description = 'Lê as vendas por artigo do PingWin (até 31 dias, blocos de 7) e confere-as com o líquido diário.';

    public function handle(PingwinItemSalesService $service): int
    {
        $companyId = (int) $this->argument('company');
        $company = Company::find($companyId);
        if (! $company) {
            $this->error("Empresa {$companyId} não existe.");

            return self::FAILURE;
        }

        [$defaultFrom, $defaultTo] = PingwinItemSalesService::nightlyWindow();
        $from = (string) ($this->option('from') ?: $defaultFrom);
        $to = (string) ($this->option('to') ?: $defaultTo);
        if ($this->option('from') && ! $this->option('to')) {
            // Só --from: um bloco de 7 dias a partir dele (sem passar de ontem).
            $to = CarbonImmutable::parse($from)->addDays(PingwinItemSalesService::DAYS_PER_CALL - 1)
                ->min(CarbonImmutable::yesterday())->toDateString();
        }
        $dryRun = (bool) $this->option('dry-run');
        $enabled = PingwinItemSalesService::isEnabled($companyId);

        $this->line("Empresa: {$company->fiscal_name} ({$companyId})");
        $this->line('Interruptor: ' . ($enabled ? 'ligado' : 'desligado'));
        $this->line("Período: {$from} a {$to}" . ($dryRun ? ' (simulação: nada é gravado)' : ''));

        if (! $dryRun && ! $enabled) {
            $this->error('O interruptor está desligado: use --dry-run, ou ligue-o com pingwin:item-sales-switch ' . $companyId . ' on.');

            return self::FAILURE;
        }

        $started = microtime(true);
        try {
            $result = $service->sync($companyId, $from, $to, $dryRun);
        } catch (\Throwable $e) {
            $this->error('Falhou: ' . $e->getMessage());

            return self::FAILURE;
        }
        $seconds = round(microtime(true) - $started, 1);

        $this->table(
            ['Loja', 'Dia', 'Artigos', 'Soma dos artigos', 'Líquido diário', 'Diferença', 'Estado'],
            array_map(fn ($d) => [
                $d['location'],
                $d['date'],
                $d['rows'],
                self::eur($d['items_net_cents']),
                $d['daily_net_cents'] === null ? '(sem resumo)' : self::eur($d['daily_net_cents']),
                $d['daily_net_cents'] === null ? '' : self::eur($d['items_net_cents'] - $d['daily_net_cents']),
                self::label($d['status']),
            ], $result['days'])
        );

        $total = array_sum($result['families']);
        if ($total > 0) {
            $this->line('Famílias com mais peso (sem IVA):');
            foreach (array_slice($result['families'], 0, 8, true) as $path => $cents) {
                $this->line(sprintf('  %5.1f%%  %s', $cents / $total * 100, $path));
            }
        }
        if ($result['ignored_stores'] !== []) {
            $this->warn('Lojas do PingWin sem cadastro (ignoradas): ' . implode(', ', $result['ignored_stores']));
        }

        $counts = array_count_values(array_column($result['days'], 'status'));
        $this->line('Pedidos ao PingWin: ' . $result['calls'] . "; tempo: {$seconds} s.");
        $this->line('Estados: ' . implode(', ', array_map(fn ($s, $n) => self::label($s) . " {$n}", array_keys($counts), $counts)));
        $this->info($dryRun ? 'Simulação concluída: nada foi gravado.' : 'Concluído: vendas por artigo gravadas.');

        return self::SUCCESS;
    }

    private static function eur(int $cents): string
    {
        return number_format($cents / 100, 2, ',', ' ') . ' €';
    }

    private static function label(string $status): string
    {
        return [
            'ok' => 'OK',
            'mismatch' => 'NÃO BATE',
            'unverified' => 'sem resumo para conferir',
            'empty' => 'sem vendas',
            'empty_protected' => 'VAZIO (protegido)',
        ][$status] ?? $status;
    }
}
