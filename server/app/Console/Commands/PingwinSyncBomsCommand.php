<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PingwinBomSyncService;
use Illuminate\Console\Command;

/**
 * XPLENDOR — Fichas técnicas do PingWin (Fatia 1, SÓ LEITURA): todas as versões, as lojas de cada
 * versão e as linhas com custo em "" e em cada armazém, com os semi-produtos (recursivo até 5
 * níveis). Uma transação por artigo; uma falha não pára os outros.
 *
 * ⚠️ Faz `docker exec` ao container do scraper → corre no WORKER, e só fora do horário de serviço:
 *   docker exec xplendor-worker php artisan pingwin:sync-boms 5 --product=1649601156985
 *   docker exec xplendor-worker php artisan pingwin:sync-boms 5 --limit=10 --dry-run
 * A leitura completa corre de noite, pelo agendamento (05:45, Lisboa).
 */
class PingwinSyncBomsCommand extends Command
{
    protected $signature = 'pingwin:sync-boms
        {company : ID da empresa (ex.: 5 = Yuko)}
        {--product=* : Só estes artigos (id do PingWin; repetível ou separado por vírgulas)}
        {--limit= : Máximo de artigos nesta execução}
        {--dry-run : Lê e mostra, sem gravar}';

    protected $description = 'Sincroniza (leitura) as fichas técnicas do PingWin: versões, lojas, linhas e custo por armazém.';

    public function handle(PingwinBomSyncService $sync): int
    {
        $companyId = (int) $this->argument('company');
        $products = collect((array) $this->option('product'))
            ->flatMap(fn ($v) => explode(',', (string) $v))->map(fn ($v) => trim($v))->filter()->unique()->values()->all();
        $ids = $products ?: $sync->candidates($companyId);
        if ($this->option('limit') !== null && $this->option('limit') !== '') {
            $ids = array_slice($ids, 0, max(0, (int) $this->option('limit')));
        }
        $dry = (bool) $this->option('dry-run');

        $this->info('Artigos a ler: ' . count($ids) . ($dry ? ' (dry-run: não grava)' : ''));
        if (! $ids) {
            return self::SUCCESS;
        }

        $t0 = microtime(true);
        $total = ['articles' => 0, 'versions' => 0, 'lines' => 0, 'failed' => 0];
        $done = [];
        foreach (array_chunk($ids, PingwinBomSyncService::BATCH_SIZE) as $chunk) {
            $chunk = array_values(array_diff($chunk, $done));
            if (! $chunk) {
                continue;
            }
            try {
                $b = $sync->syncBatch($companyId, $chunk, $dry, $done);
            } catch (\Throwable $e) {
                $this->error('  lote falhou: ' . mb_substr($e->getMessage(), 0, 300));
                $total['failed'] += count($chunk);
                continue;
            }
            $done = array_values(array_unique(array_merge($done, $b['read'])));
            foreach (['articles', 'versions', 'lines', 'failed'] as $k) {
                $total[$k] += $b[$k];
            }
            $this->line(sprintf('  lote: %d artigos · %d versões · %d linhas · %d falhas   (acumulado %d)',
                $b['articles'], $b['versions'], $b['lines'], $b['failed'], $total['articles']));
            foreach ($b['errors'] as $pid => $err) {
                $this->warn("  {$pid}: {$err}");
            }
            if ($dry) {
                $this->printDry($b['boms'] ?? []);
            }
        }

        $secs = microtime(true) - $t0;
        $this->table(['artigos', 'versões', 'linhas', 'falhas', 'tempo'], [[
            $total['articles'], $total['versions'], $total['lines'], $total['failed'], sprintf('%.1f s', $secs),
        ]]);

        return $total['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function printDry(array $boms): void
    {
        foreach ($boms as $pid => $bom) {
            $this->line("  {$pid} {$bom['code']} {$bom['description']}");
            foreach ($bom['versions'] ?? [] as $v) {
                $stores = collect($v['stores'] ?? [])->map(fn ($s) => $s['store_code'] . ($s['active'] ? '' : ' (inativa)'))->implode(', ');
                $cost = collect($v['lines'][''] ?? [])->sum(fn ($l) => (float) ($l['cost'] ?? 0));
                $this->line(sprintf('    versão %s desde %s · %d linhas · custo PingWin %.4f € · lojas: %s',
                    $v['key'], $v['valid_from'] ?? '?', count($v['lines'][''] ?? []), $cost, $stores));
            }
        }
    }
}
