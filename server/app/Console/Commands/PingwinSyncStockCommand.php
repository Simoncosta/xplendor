<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PingwinService;
use App\Services\Restaurant\StockSyncService;
use Illuminate\Console\Command;

/**
 * XPLENDOR — Stock do PingWin (Fatia 7a, SÓ LEITURA): os armazéns e o stock de cada artigo, uma linha
 * por armazém. Artigo a artigo (o PingWin não tem uma listagem de stock de todos os artigos), em lotes
 * de 50; uma falha num artigo não pára os outros.
 *
 * ⚠️ Faz `docker exec` ao container do scraper → corre no WORKER:
 *   docker exec xplendor-worker php artisan pingwin:sync-stock 5 --product=584955579139631978
 *   docker exec xplendor-worker php artisan pingwin:sync-stock 5 --limit=20 --dry-run
 * A leitura completa corre de noite, pelo agendamento (04:15, Lisboa).
 */
class PingwinSyncStockCommand extends Command
{
    protected $signature = 'pingwin:sync-stock
        {company : ID da empresa (ex.: 5)}
        {--product=* : Só estes artigos (id do PingWin; repetível ou separado por vírgulas)}
        {--limit= : Máximo de artigos nesta execução}
        {--dry-run : Lê e mostra, sem gravar}';

    protected $description = 'Sincroniza (leitura) o stock do PingWin: armazéns e stock por artigo e armazém.';

    public function handle(StockSyncService $sync, PingwinService $pingwin): int
    {
        $companyId = (int) $this->argument('company');
        $products = collect((array) $this->option('product'))
            ->flatMap(fn ($v) => explode(',', (string) $v))->map(fn ($v) => trim($v))->filter()->unique()->values()->all();
        $ids = $products ?: $sync->candidates($companyId);
        if ($this->option('limit') !== null && $this->option('limit') !== '') {
            $ids = array_slice($ids, 0, max(0, (int) $this->option('limit')));
        }
        $dry = (bool) $this->option('dry-run');

        $estimate = StockSyncService::estimateSeconds(count($ids));
        $this->info(sprintf('Artigos a ler: %d. Tempo estimado: %s (%s s por artigo, em lotes de %d).%s',
            count($ids), self::duration($estimate), number_format(StockSyncService::SECONDS_PER_ARTICLE, 1, ',', ''), StockSyncService::BATCH_SIZE,
            $dry ? ' Dry-run: lê e mostra, não grava.' : ''));

        try {
            if ($dry) {
                foreach ($pingwin->fetchWarehouses($companyId)['warehouses'] as $w) {
                    $this->line(sprintf('  armazém %s %s%s', $w['code'] ?? '', $w['description'] ?? '', ! empty($w['deleted']) ? ' (anulado)' : ''));
                }
            } else {
                $this->line('  armazéns gravados: ' . $sync->syncWarehouses($companyId));
                $this->line('  artigos contáveis: ' . app(\App\Services\Restaurant\CountableProductsService::class)->refresh($companyId));
                try {
                    $o = app(\App\Services\Restaurant\Transfers\TransferOrdersService::class)->sync($companyId);
                    $this->line(sprintf('  encomendas dos restaurantes: %d (%d linhas, %d por ler)', $o['requests'], $o['lines'], $o['errors']));
                } catch (\Throwable $e) {
                    $this->warn('  encomendas: ' . mb_substr($e->getMessage(), 0, 300));
                }
            }
        } catch (\Throwable $e) {
            $this->warn('  armazéns: ' . mb_substr($e->getMessage(), 0, 300));
        }
        if (! $ids) {
            return self::SUCCESS;
        }

        $run = $dry ? null : $sync->startRun($companyId, 'comando', count($ids));
        $t0 = microtime(true);
        $total = ['articles' => 0, 'rows' => 0, 'failed' => 0];
        $secs = [];
        foreach (array_chunk($ids, StockSyncService::BATCH_SIZE) as $chunk) {
            try {
                $b = $sync->syncBatch($companyId, $chunk, $dry);
            } catch (\Throwable $e) {
                $this->error('  lote falhou: ' . mb_substr($e->getMessage(), 0, 300));
                $total['failed'] += count($chunk);
                $run?->increment('products_failed', count($chunk));
                continue;
            }
            if ($run) {
                $sync->addToRun($run, $b);
            }
            foreach (['articles', 'rows', 'failed'] as $k) {
                $total[$k] += $b[$k];
            }
            $secs = array_merge($secs, array_values($b['seconds']));
            $this->line(sprintf('  lote: %d artigos · %d linhas · %d falhas   (acumulado %d)', $b['articles'], $b['rows'], $b['failed'], $total['articles']));
            foreach ($b['errors'] as $pid => $err) {
                $this->warn("  {$pid}: {$err}");
            }
            if ($dry) {
                $this->printDry($b['stock'] ?? []);
            }
        }
        if ($run) {
            $sync->finishRun($run);
        }

        $elapsed = microtime(true) - $t0;
        $avg = $secs ? array_sum($secs) / count($secs) : null;
        $this->table(['artigos', 'linhas', 'falhas', 'tempo', 'por artigo'], [[
            $total['articles'], $total['rows'], $total['failed'], self::duration((int) round($elapsed)), $avg !== null ? number_format($avg, 2, ',', '') . ' s' : '—',
        ]]);

        return $total['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function printDry(array $stock): void
    {
        foreach ($stock as $pid => $s) {
            $a = $s['article'] ?? [];
            $this->line(sprintf('  %s %s %s · %s (mexe stock: %s)', $pid, $a['code'] ?? '', $a['description'] ?? '', $a['stockconfig'] ?? '?',
                ($a['movestock'] ?? null) === null ? '?' : (($a['movestock'] ?? 0) ? 'sim' : 'não')));
            foreach ($s['rows'] ?? [] as $r) {
                $this->line(sprintf('    %-22s %12s %-10s PCM %s · PCU %s · valorização %s · mín %s · máx %s',
                    $r['warehouse_name'], number_format((float) $r['qnt'], 3, ',', ' '), $r['unit_name'],
                    $r['pcm'] ?? '—', $r['pcu'] ?? '—', $r['valorization_date'] ?? '—', $r['min_stock'] ?? '—', $r['max_stock'] ?? '—'));
            }
        }
    }

    private static function duration(int $seconds): string
    {
        return $seconds < 90 ? "{$seconds} s" : sprintf('%d min %02d s', intdiv($seconds, 60), $seconds % 60);
    }
}
