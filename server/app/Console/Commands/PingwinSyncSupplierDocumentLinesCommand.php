<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SupplierDocumentLinesService;
use Illuminate\Console\Command;

/**
 * XPLENDOR — Linhas dos documentos de fornecedor (F4, SÓ LEITURA): sync MANUAL e síncrona,
 * incremental (sem leitura, falhados, ou com total/estado mudado), em lotes de 50.
 * Cada documento é aberto, lido e FECHADO logo (OPEN → GET → CLOSE).
 *
 * ⚠️ Faz `docker exec` ao container do scraper → corre no WORKER:
 *   docker exec xplendor-worker php artisan pingwin:sync-supplier-document-lines 5 --limit=50
 *   docker exec xplendor-worker php artisan pingwin:sync-supplier-document-lines 5 --doc="VFT BOVFT/1020"
 * Backfill completo (sem --limit) só fora do horário de trabalho.
 */
class PingwinSyncSupplierDocumentLinesCommand extends Command
{
    protected $signature = 'pingwin:sync-supplier-document-lines
        {company : ID da empresa (ex.: 5 = Yuko)}
        {--from= : Só documentos com data ≥ AAAA-MM-DD}
        {--to= : Só documentos com data ≤ AAAA-MM-DD}
        {--doc= : Só este documento (docheader_id ou nº, ex.: "VFT BOVFT/1020") — relê sempre}
        {--limit= : Máximo de documentos nesta execução}';

    protected $description = 'Sincroniza (leitura) as linhas dos documentos de fornecedor PingWin (fechados: VFT, FR C, NC, ND).';

    public function handle(SupplierDocumentLinesService $lines): int
    {
        $companyId = (int) $this->argument('company');
        $opts = array_filter([
            'from'  => $this->option('from'),
            'to'    => $this->option('to'),
            'doc'   => $this->option('doc'),
            'limit' => $this->option('limit'),
        ], fn ($v) => $v !== null && $v !== '');

        $pending = $lines->candidates($companyId, $opts)->count();
        $this->info("Documentos a ler: {$pending}" . (isset($opts['limit']) ? " (limite {$opts['limit']})" : ''));
        if ($pending === 0) {
            return self::SUCCESS;
        }

        $t0 = microtime(true);
        $total = $lines->sync($companyId, $opts, function (array $b, array $t) {
            $this->line(sprintf('  lote: %d docs · ok %d · failed %d · diff %d · %d linhas · %.1f s   (acumulado %d)',
                $b['docs'], $b['ok'], $b['failed'], $b['diff'], $b['lines'], $b['ms'] / 1000, $t['docs']));
        });
        $secs = microtime(true) - $t0;

        $this->table(['documentos', 'ok', 'failed', 'conferência diff', 'não fechados', 'linhas', 'tempo', 's/doc'], [[
            $total['docs'], $total['ok'], $total['failed'], $total['diff'], $total['not_closed'], $total['lines'],
            sprintf('%.1f s', $secs), $total['docs'] ? sprintf('%.2f', $secs / $total['docs']) : '—',
        ]]);

        return $total['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
